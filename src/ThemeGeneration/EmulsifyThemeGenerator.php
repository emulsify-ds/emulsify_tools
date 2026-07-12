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
    $sourceDirectory = $this->getSourceDirectory($request->starterkitMachineName);
    if ($sourceDirectory === NULL) {
      return $this->starterkitGenerator->generate($request);
    }

    if ($this->isValidStarterkitSource($sourceDirectory, $request->starterkitMachineName)) {
      return $this->starterkitGenerator->generate($request);
    }

    if ($this->isLegacySource($sourceDirectory, $request->starterkitMachineName)) {
      return ($this->legacyGeneratorFactory)()->generate($request);
    }

    // Modern markers that are incomplete or invalid still belong to Drupal
    // core. Never retry them through the legacy compatibility generator.
    return $this->starterkitGenerator->generate($request);
  }

  /**
   * Resolves the installed Whisk source directory.
   */
  private function getSourceDirectory(string $starterkitMachineName): ?string {
    try {
      $emulsifyPath = $this->themeExtensionList->getPath('emulsify');
    }
    catch (UnknownExtensionException) {
      return NULL;
    }

    return rtrim($this->appRoot, '/') . '/' . trim($emulsifyPath, '/') . '/' . $starterkitMachineName;
  }

  /**
   * Returns whether the source is a usable Drupal Starterkit.
   */
  private function isValidStarterkitSource(string $directory, string $machineName): bool {
    $info = $this->decodeYamlFile("{$directory}/{$machineName}.info.yml");
    $starterkit = $this->decodeYamlFile("{$directory}/{$machineName}.starterkit.yml");

    return $info !== NULL
      && $starterkit !== NULL
      && ($info['type'] ?? NULL) === 'theme'
      && is_string($info['name'] ?? NULL);
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
