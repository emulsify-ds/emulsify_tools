<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools\ThemeGeneration;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Extension\Exception\UnknownExtensionException;
use Drupal\Core\Extension\ThemeExtensionList;

/**
 * Selects the generator matching the installed Emulsify Whisk source format.
 */
final class EmulsifyThemeGenerator implements ThemeGeneratorInterface {

  /**
   * Actionable error for an unavailable Emulsify base theme.
   */
  private const MISSING_BASE_THEME_MESSAGE = 'The Emulsify base theme was not found. Install a compatible Emulsify Drupal 7.x release before generating a child theme.';

  /**
   * Actionable error for an unavailable Whisk source directory.
   */
  private const MISSING_STARTERKIT_MESSAGE = 'The Emulsify Whisk Starterkit was not found. Install a compatible Emulsify Drupal 7.x release before generating a child theme.';

  /**
   * Actionable error for unavailable Whisk Starterkit metadata.
   */
  private const MISSING_STARTERKIT_CONFIG_MESSAGE = 'The Emulsify Whisk Starterkit metadata file whisk.starterkit.yml was not found. Install a compatible Emulsify Drupal 7.x release before generating a child theme.';

  /**
   * Creates the legacy generator only when the legacy source is selected.
   *
   * @var \Closure(): \Drupal\emulsify_tools\ThemeGeneration\ThemeGeneratorInterface
   */
  private readonly \Closure $legacyGeneratorFactory;

  /**
   * Creates an Emulsify theme generator.
   */
  public function __construct(
    private readonly ThemeExtensionList $themeExtensionList,
    private readonly ThemeGeneratorInterface $starterkitGenerator,
    \Closure $legacyGeneratorFactory,
    private readonly string $appRoot,
  ) {
    $this->legacyGeneratorFactory = $legacyGeneratorFactory;
  }

  /**
   * {@inheritdoc}
   */
  public function generate(ThemeGenerationRequest $request): ThemeGenerationResult {
    $baseThemeDirectory = $this->getBaseThemeDirectory();
    if ($baseThemeDirectory === NULL || !is_dir($baseThemeDirectory)) {
      return new ThemeGenerationResult(1, [self::MISSING_BASE_THEME_MESSAGE]);
    }

    $sourceDirectory = $baseThemeDirectory . '/' . $request->starterkitMachineName;
    if (!is_dir($sourceDirectory)) {
      return new ThemeGenerationResult(1, [self::MISSING_STARTERKIT_MESSAGE]);
    }

    if ($this->isLegacySource($sourceDirectory, $request->starterkitMachineName)) {
      return ($this->legacyGeneratorFactory)()->generate($request);
    }

    if (!is_file("{$sourceDirectory}/{$request->starterkitMachineName}.starterkit.yml")) {
      return new ThemeGenerationResult(1, [self::MISSING_STARTERKIT_CONFIG_MESSAGE]);
    }

    // Modern parsing and generation failures belong to Drupal core. Never
    // retry them through the legacy compatibility generator.
    return $this->starterkitGenerator->generate($request);
  }

  /**
   * Resolves the installed Emulsify base-theme directory.
   */
  private function getBaseThemeDirectory(): ?string {
    try {
      $emulsifyPath = $this->themeExtensionList->getPath('emulsify');
    }
    catch (UnknownExtensionException) {
      return NULL;
    }

    return rtrim($this->appRoot, '/') . '/' . trim($emulsifyPath, '/');
  }

  /**
   * Returns whether the source has the Emulsify Drupal 6.x legacy structure.
   */
  private function isLegacySource(string $directory, string $machineName): bool {
    if (
      is_file("{$directory}/{$machineName}.info.yml")
      || is_file("{$directory}/{$machineName}.starterkit.yml")
    ) {
      return FALSE;
    }

    $info = $this->decodeYamlFile("{$directory}/{$machineName}.info.emulsify.yml");

    return $info !== NULL
      && ($info['type'] ?? NULL) === 'theme'
      && ($info['base theme'] ?? NULL) === 'emulsify';
  }

  /**
   * Decodes a YAML mapping or returns NULL when it is unavailable or invalid.
   *
   * @return array<string, mixed>|null
   *   The decoded mapping.
   */
  private function decodeYamlFile(string $path): ?array {
    if (!is_file($path)) {
      return NULL;
    }

    $contents = file_get_contents($path);
    if ($contents === FALSE) {
      return NULL;
    }

    try {
      $decoded = Yaml::decode($contents);
    }
    catch (\Throwable) {
      return NULL;
    }

    return is_array($decoded) ? $decoded : NULL;
  }

}
