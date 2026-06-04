<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools\Drush\Commands;

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
   * Creates the command class.
   */
  public function __construct(
    private readonly FaviconCommandManager $faviconCommandManager,
  ) {
    parent::__construct();
  }

  /**
   * Generates the current favicon package for an Emulsify-based theme.
   */
  #[CLI\Command(name: 'emulsify_tools:favicon-generate')]
  #[CLI\Help(
    description: 'Generate or refresh a favicon package from Emulsify Drupal theme settings.',
    synopsis: 'Use after deploy or config import so generated favicon files exist before page requests attach them. The target theme must be Emulsify or an Emulsify child theme configured through the Emulsify Drupal theme settings form.',
  )]
  #[CLI\Argument(name: 'theme', description: 'Optional Emulsify or Emulsify child theme machine name. Defaults to the configured frontend theme.')]
  #[CLI\Usage(name: 'emulsify_tools:favicon-generate', description: 'Generate the favicon package for the configured default frontend theme.')]
  #[CLI\Usage(name: 'emulsify_tools:favicon-generate my_theme', description: 'Generate the favicon package for the my_theme Emulsify child theme.')]
  public function generate(?string $theme = NULL): int {
    try {
      $result = $this->faviconCommandManager->generate($theme);
    }
    catch (\Throwable $exception) {
      $this->logger()->error($exception->getMessage());
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
   * Reports favicon package status for an Emulsify-based theme.
   */
  #[CLI\Command(name: 'emulsify_tools:favicon-status')]
  #[CLI\Help(
    description: 'Check favicon package, dependency, and portable source status for an Emulsify-based theme.',
    synopsis: 'Reports whether favicon generation is enabled, whether the generated package exists, whether GD and Imagick are available, and whether portable SVG source config can regenerate the package.',
  )]
  #[CLI\Argument(name: 'theme', description: 'Optional Emulsify or Emulsify child theme machine name. Defaults to the configured frontend theme.')]
  #[CLI\Usage(name: 'emulsify_tools:favicon-status', description: 'Check favicon package status for the configured default frontend theme.')]
  #[CLI\Usage(name: 'emulsify_tools:favicon-status my_theme', description: 'Check favicon package status for the my_theme Emulsify child theme.')]
  public function status(?string $theme = NULL): int {
    try {
      $result = $this->faviconCommandManager->status($theme);
    }
    catch (\Throwable $exception) {
      $this->logger()->error($exception->getMessage());
      return 1;
    }

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

    return 0;
  }

  /**
   * Resets a theme back to the default favicon behavior.
   */
  #[CLI\Command(name: 'emulsify_tools:favicon-reset')]
  #[CLI\Help(
    description: 'Remove generated favicon package state and restore default favicon behavior for an Emulsify-based theme.',
    synopsis: 'Use when intentionally discarding generated package metadata and assets. Configure and save the Emulsify Drupal theme settings form again, or run favicon-generate after config import, to recreate the package.',
  )]
  #[CLI\Argument(name: 'theme', description: 'Optional Emulsify or Emulsify child theme machine name. Defaults to the configured frontend theme.')]
  #[CLI\Usage(name: 'emulsify_tools:favicon-reset', description: 'Reset favicon package state for the configured default frontend theme.')]
  #[CLI\Usage(name: 'emulsify_tools:favicon-reset my_theme', description: 'Reset favicon package state for the my_theme Emulsify child theme.')]
  public function reset(?string $theme = NULL): int {
    try {
      $result = $this->faviconCommandManager->reset($theme);
    }
    catch (\Throwable $exception) {
      $this->logger()->error($exception->getMessage());
      return 1;
    }

    $this->logger()->notice(sprintf('Reset generated favicon settings for %s and restored theme defaults.', $result['theme']));
    return 0;
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
