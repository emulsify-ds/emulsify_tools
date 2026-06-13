<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools\Drush\Commands;

use Consolidation\AnnotatedCommand\CommandResult;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\emulsify_tools\Favicon\FaviconCommandManager;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Provides Drush commands for Emulsify favicon package operations.
 */
final class FaviconCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Default non-structured status output format.
   */
  private const STATUS_FORMAT_HUMAN = 'human';

  /**
   * Creates the command class.
   */
  public function __construct(
    private readonly FaviconCommandManager $faviconCommandManager,
  ) {
    parent::__construct();
  }

  /**
   * Generates the current favicon package for an Emulsify-based theme.
   *
   * @param string|null $theme
   *   Optional theme machine name.
   * @param array<string, mixed> $options
   *   Command options.
   */
  #[CLI\Command(name: 'emulsify_tools:favicon-generate')]
  #[CLI\Help(
    description: 'Generate or refresh a favicon package from Emulsify Drupal theme settings.',
    synopsis: 'Use after deploy or config import so generated favicon files exist before page requests attach them. The target theme must be Emulsify or an Emulsify child theme configured through the Emulsify Drupal theme settings form.',
  )]
  #[CLI\Argument(name: 'theme', description: 'Optional Emulsify or Emulsify child theme machine name. Defaults to the configured frontend theme.')]
  #[CLI\Option(name: 'all', description: 'Generate favicon packages for every installed Emulsify-based theme.')]
  #[CLI\Usage(name: 'emulsify_tools:favicon-generate', description: 'Generate the favicon package for the configured default frontend theme.')]
  #[CLI\Usage(name: 'emulsify_tools:favicon-generate my_theme', description: 'Generate the favicon package for the my_theme Emulsify child theme.')]
  public function generate(?string $theme = NULL, array $options = ['all' => FALSE]): int {
    if ($this->isAllOption($options)) {
      return $this->generateAll();
    }

    return $this->generateOne($theme);
  }

  /**
   * Generates a favicon package for one theme.
   */
  private function generateOne(?string $theme, bool $prefixThemeErrors = FALSE): int {
    try {
      $result = $this->faviconCommandManager->generate($theme);
    }
    catch (\Throwable $exception) {
      $message = $prefixThemeErrors && $theme !== NULL
        ? sprintf('%s: %s', $theme, $exception->getMessage())
        : $exception->getMessage();
      $this->logger()->error($message);
      return 1;
    }

    foreach ($result['warnings'] as $warning) {
      $this->logger()->warning((string) $warning);
    }

    $summary = sprintf(
      '%s: %s%s%s',
      $result['theme'],
      $result['generated'] ? 'generated favicon package' : 'favicon package already current',
      !empty($result['result']['path']) ? ' at ' . $result['result']['path'] : '',
      !empty($result['result']['hash']) ? ' (' . $result['result']['hash'] . ')' : '',
    );
    $this->logger()->notice($summary);

    return 0;
  }

  /**
   * Generates favicon packages for every supported theme.
   */
  private function generateAll(): int {
    $themeNames = $this->faviconCommandManager->getSupportedThemeNames();
    if ($themeNames === []) {
      $this->logger()->error('No installed Emulsify-based themes were found.');
      return 1;
    }

    $exitCode = 0;
    foreach ($themeNames as $themeName) {
      $exitCode = max($exitCode, $this->generateOne($themeName, TRUE));
    }

    return $exitCode;
  }

  /**
   * Reports favicon package status for an Emulsify-based theme.
   *
   * @param string|null $theme
   *   Optional theme machine name.
   * @param array<string, mixed> $options
   *   Command options.
   */
  #[CLI\Command(name: 'emulsify_tools:favicon-status')]
  #[CLI\Help(
    description: 'Check favicon package, dependency, and portable source status for an Emulsify-based theme.',
    synopsis: 'Reports whether favicon generation is enabled, whether the generated package exists, whether GD and Imagick are available, and whether portable SVG source config can regenerate the package.',
  )]
  #[CLI\Argument(name: 'theme', description: 'Optional Emulsify or Emulsify child theme machine name. Defaults to the configured frontend theme.')]
  #[CLI\Option(name: 'all', description: 'Report status for every installed Emulsify-based theme.')]
  #[CLI\Option(name: 'format', description: 'Output structured status data. Supported Drush formats include table, json, and yaml. Defaults to human-readable text.')]
  #[CLI\Usage(name: 'emulsify_tools:favicon-status', description: 'Check favicon package status for the configured default frontend theme.')]
  #[CLI\Usage(name: 'emulsify_tools:favicon-status my_theme', description: 'Check favicon package status for the my_theme Emulsify child theme.')]
  public function status(
    ?string $theme = NULL,
    array $options = [
      'all' => FALSE,
      'format' => self::STATUS_FORMAT_HUMAN,
    ],
  ): int|RowsOfFields|CommandResult {
    $format = $this->getStatusFormat($options);
    if ($this->isAllOption($options)) {
      return $this->statusAll($format);
    }

    try {
      $result = $this->faviconCommandManager->status($theme);
    }
    catch (\Throwable $exception) {
      $this->logger()->error($exception->getMessage());
      return 1;
    }

    if ($this->isStructuredStatusFormat($format)) {
      return new RowsOfFields([$this->buildStatusRow($result, $format)]);
    }

    $this->logStatus($result);
    return 0;
  }

  /**
   * Reports favicon package status for every supported theme.
   */
  private function statusAll(string $format): int|RowsOfFields|CommandResult {
    $themeNames = $this->faviconCommandManager->getSupportedThemeNames();
    $structured = $this->isStructuredStatusFormat($format);
    if ($themeNames === []) {
      $message = 'No installed Emulsify-based themes were found.';
      if ($structured) {
        return CommandResult::dataWithExitCode(new RowsOfFields([
          $this->buildStatusErrorRow('', $message, $format),
        ]), 1);
      }

      $this->logger()->error($message);
      return 1;
    }

    $exitCode = 0;
    $rows = [];
    foreach ($themeNames as $themeName) {
      try {
        $result = $this->faviconCommandManager->status($themeName);
      }
      catch (\Throwable $exception) {
        $exitCode = 1;
        if ($structured) {
          $rows[] = $this->buildStatusErrorRow($themeName, $exception->getMessage(), $format);
          continue;
        }

        $this->logger()->error(sprintf('%s: %s', $themeName, $exception->getMessage()));
        continue;
      }

      if ($structured) {
        $rows[] = $this->buildStatusRow($result, $format);
        continue;
      }

      $this->logStatus($result);
    }

    if ($structured) {
      $data = new RowsOfFields($rows);
      return $exitCode === 0 ? $data : CommandResult::dataWithExitCode($data, $exitCode);
    }

    return $exitCode;
  }

  /**
   * Logs a human-readable status result.
   *
   * @param array<string, mixed> $result
   *   The status result.
   */
  private function logStatus(array $result): void {
    $settings = $result['settings'];
    $status = $result['package_status'];
    $dependencies = $result['dependencies'];

    $this->logger()->notice(sprintf('Theme: %s', $result['theme']));
    $this->logger()->notice(sprintf('Generated favicon package enabled: %s', $this->formatBoolean((bool) ($settings['favicon_package_enabled'] ?? FALSE))));
    $this->logger()->notice(sprintf('Package state: %s', $status['state'] ?? 'unknown'));
    $this->logger()->notice(sprintf('GD available: %s', $this->formatBoolean((bool) ($dependencies['gd'] ?? FALSE))));
    $this->logger()->notice(sprintf('Imagick available: %s', $this->formatBoolean((bool) ($dependencies['imagick'] ?? FALSE))));
    $this->logger()->notice(sprintf('Active theme package exists: %s', $this->formatBoolean((bool) ($status['package_exists'] ?? FALSE))));
    $this->logger()->notice(sprintf('Portable SVG source available: %s', $this->formatBoolean((bool) ($status['portable_source_available'] ?? FALSE))));

    if (!empty($status['hash'])) {
      $this->logger()->notice(sprintf('Package hash: %s', $status['hash']));
    }
    if (!empty($status['path'])) {
      $this->logger()->notice(sprintf('Package path: %s', $status['path']));
    }
    if (!empty($status['portable_source_available'])) {
      $this->logger()->notice(sprintf('Portable SVG source size: %s bytes', (int) ($status['portable_source_size'] ?? 0)));
    }
    if (!empty($status['generated_at'])) {
      $this->logger()->notice(sprintf('Generated at: %s', $this->formatTimestamp((int) $status['generated_at'])));
    }
    if (!empty($status['analysis_warnings'])) {
      foreach ($status['analysis_warnings'] as $warning) {
        $this->logger()->warning((string) $warning);
      }
    }
    if (!empty($status['error'])) {
      $this->logger()->warning(sprintf('Status error: %s', $status['error']));
    }
  }

  /**
   * Resets a theme back to the default favicon behavior.
   *
   * @param string|null $theme
   *   Optional theme machine name.
   * @param array<string, mixed> $options
   *   Command options.
   */
  #[CLI\Command(name: 'emulsify_tools:favicon-reset')]
  #[CLI\Help(
    description: 'Remove generated favicon package state and restore default favicon behavior for an Emulsify-based theme.',
    synopsis: 'Use when intentionally discarding generated package metadata and assets. Configure and save the Emulsify Drupal theme settings form again, or run favicon-generate after config import, to recreate the package.',
  )]
  #[CLI\Argument(name: 'theme', description: 'Optional Emulsify or Emulsify child theme machine name. Defaults to the configured frontend theme.')]
  #[CLI\Option(name: 'all', description: 'Reset favicon package state for every installed Emulsify-based theme.')]
  #[CLI\Usage(name: 'emulsify_tools:favicon-reset', description: 'Reset favicon package state for the configured default frontend theme.')]
  #[CLI\Usage(name: 'emulsify_tools:favicon-reset my_theme', description: 'Reset favicon package state for the my_theme Emulsify child theme.')]
  public function reset(?string $theme = NULL, array $options = ['all' => FALSE]): int {
    if ($this->isAllOption($options)) {
      return $this->resetAll();
    }

    return $this->resetOne($theme);
  }

  /**
   * Resets favicon package state for one theme.
   */
  private function resetOne(?string $theme, bool $prefixThemeErrors = FALSE): int {
    try {
      $result = $this->faviconCommandManager->reset($theme);
    }
    catch (\Throwable $exception) {
      $message = $prefixThemeErrors && $theme !== NULL
        ? sprintf('%s: %s', $theme, $exception->getMessage())
        : $exception->getMessage();
      $this->logger()->error($message);
      return 1;
    }

    $this->logger()->notice(sprintf('Reset generated favicon settings for %s and restored theme defaults.', $result['theme']));
    return 0;
  }

  /**
   * Resets favicon package state for every supported theme.
   */
  private function resetAll(): int {
    $themeNames = $this->faviconCommandManager->getSupportedThemeNames();
    if ($themeNames === []) {
      $this->logger()->error('No installed Emulsify-based themes were found.');
      return 1;
    }

    $exitCode = 0;
    foreach ($themeNames as $themeName) {
      $exitCode = max($exitCode, $this->resetOne($themeName, TRUE));
    }

    return $exitCode;
  }

  /**
   * Builds one structured status row.
   *
   * @param array<string, mixed> $result
   *   The status result.
   * @param string $format
   *   The requested output format.
   *
   * @return array<string, mixed>
   *   A structured status row.
   */
  private function buildStatusRow(array $result, string $format): array {
    $settings = $result['settings'];
    $status = $result['package_status'];
    $dependencies = $result['dependencies'];

    return [
      'theme' => (string) $result['theme'],
      'package_enabled' => $this->formatStructuredBoolean((bool) ($settings['favicon_package_enabled'] ?? FALSE), $format),
      'package_state' => (string) ($status['state'] ?? 'unknown'),
      'gd' => $this->formatStructuredBoolean((bool) ($dependencies['gd'] ?? FALSE), $format),
      'imagick' => $this->formatStructuredBoolean((bool) ($dependencies['imagick'] ?? FALSE), $format),
      'package_exists' => $this->formatStructuredBoolean((bool) ($status['package_exists'] ?? FALSE), $format),
      'portable_source_available' => $this->formatStructuredBoolean((bool) ($status['portable_source_available'] ?? FALSE), $format),
      'portable_source_size' => (int) ($status['portable_source_size'] ?? 0),
      'hash' => (string) ($status['hash'] ?? ''),
      'path' => (string) ($status['path'] ?? ''),
      'generated_at' => $this->formatStructuredTimestamp($status['generated_at'] ?? NULL, $format),
      'warnings' => $this->formatStructuredWarnings($this->collectStatusWarnings($status), $format),
    ];
  }

  /**
   * Builds a structured status row for a theme-level command error.
   *
   * @return array<string, mixed>
   *   A structured status row.
   */
  private function buildStatusErrorRow(string $themeName, string $message, string $format): array {
    $empty = $this->isMachineReadableStatusFormat($format) ? NULL : '';

    return [
      'theme' => $themeName,
      'package_enabled' => $empty,
      'package_state' => 'error',
      'gd' => $empty,
      'imagick' => $empty,
      'package_exists' => $empty,
      'portable_source_available' => $empty,
      'portable_source_size' => $empty,
      'hash' => '',
      'path' => '',
      'generated_at' => $empty,
      'warnings' => $this->formatStructuredWarnings([$message], $format),
    ];
  }

  /**
   * Collects warnings from a status result.
   *
   * @param array<string, mixed> $status
   *   Package status data.
   *
   * @return string[]
   *   Warnings for structured output.
   */
  private function collectStatusWarnings(array $status): array {
    $warnings = [];
    if (!empty($status['analysis_warnings']) && is_array($status['analysis_warnings'])) {
      foreach ($status['analysis_warnings'] as $warning) {
        $warnings[] = (string) $warning;
      }
    }
    if (!empty($status['error'])) {
      $warnings[] = sprintf('Status error: %s', $status['error']);
    }

    return $warnings;
  }

  /**
   * Returns the normalized status output format option.
   *
   * @param array<string, mixed> $options
   *   Command options.
   */
  private function getStatusFormat(array $options): string {
    $format = strtolower(trim((string) ($options['format'] ?? self::STATUS_FORMAT_HUMAN)));
    return $format === '' ? self::STATUS_FORMAT_HUMAN : $format;
  }

  /**
   * Returns whether the status command should return structured data.
   */
  private function isStructuredStatusFormat(string $format): bool {
    return $format !== self::STATUS_FORMAT_HUMAN;
  }

  /**
   * Returns whether a status format should keep machine-native value types.
   */
  private function isMachineReadableStatusFormat(string $format): bool {
    return in_array($format, ['json', 'yaml', 'yml'], TRUE);
  }

  /**
   * Formats a boolean for structured status output.
   */
  private function formatStructuredBoolean(bool $value, string $format): bool|string {
    return $this->isMachineReadableStatusFormat($format) ? $value : $this->formatBoolean($value);
  }

  /**
   * Formats warnings for structured status output.
   *
   * @param string[] $warnings
   *   Status warnings.
   * @param string $format
   *   The requested output format.
   *
   * @return string[]|string
   *   A warning list for machine-readable formats, or text for table formats.
   */
  private function formatStructuredWarnings(array $warnings, string $format): array|string {
    if ($this->isMachineReadableStatusFormat($format)) {
      return array_values($warnings);
    }

    return implode(PHP_EOL, $warnings);
  }

  /**
   * Formats a timestamp for structured status output.
   */
  private function formatStructuredTimestamp(mixed $timestamp, string $format): int|string|null {
    if (!is_numeric($timestamp)) {
      return $this->isMachineReadableStatusFormat($format) ? NULL : '';
    }

    $timestamp = (int) $timestamp;
    return $this->isMachineReadableStatusFormat($format) ? $timestamp : $this->formatTimestamp($timestamp);
  }

  /**
   * Returns whether the --all option is enabled.
   *
   * @param array<string, mixed> $options
   *   Command options.
   */
  private function isAllOption(array $options): bool {
    return filter_var($options['all'] ?? FALSE, FILTER_VALIDATE_BOOLEAN);
  }

  /**
   * Formats a boolean state for human-readable Drush output.
   */
  private function formatBoolean(bool $value): string {
    return $value ? 'yes' : 'no';
  }

  /**
   * Formats a unix timestamp for human-readable Drush output.
   */
  private function formatTimestamp(int $timestamp): string {
    return date(DATE_ATOM, $timestamp);
  }

}
