<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools\Favicon;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ThemeExtensionList;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Repairs missing favicon install and schema files for child themes.
 */
final class ChildThemeFaviconConfigRepairer {

  /**
   * The Emulsify base theme machine name.
   */
  private const BASE_THEME = 'emulsify';

  /**
   * Cached install template settings.
   *
   * @var array<string, mixed>|null
   */
  private ?array $installTemplateSettings = NULL;

  /**
   * Cached schema mapping template.
   *
   * @var array<string, mixed>|null
   */
  private ?array $schemaTemplateDefinition = NULL;

  /**
   * Creates the repairer.
   */
  public function __construct(
    #[Autowire(param: 'app.root')]
    private readonly string $appRoot,
    private readonly ThemeExtensionList $themeExtensionList,
    private readonly Filesystem $filesystem,
  ) {}

  /**
   * Repairs favicon install and schema files for Emulsify child themes.
   *
   * @return array{
   *   inspected_count: int,
   *   updated_count: int,
   *   unchanged_count: int,
   *   updated_themes: array<string, array{
   *     path: string,
   *     install: string,
   *     schema: string
   *   }>,
   *   errors: array<string, string>
   *   }
   *   A summary of the repair results.
   */
  public function repair(?string $requestedThemeName = NULL): array {
    $themes = $this->getEligibleThemes();
    if ($requestedThemeName !== NULL) {
      if (!isset($themes[$requestedThemeName])) {
        throw new \InvalidArgumentException(sprintf('Theme "%s" is not an Emulsify-based child theme in this codebase.', $requestedThemeName));
      }

      $themes = [$requestedThemeName => $themes[$requestedThemeName]];
    }

    $updatedThemes = [];
    $errors = [];

    foreach ($themes as $themeName => $theme) {
      try {
        $result = $this->repairTheme($theme);
      }
      catch (\Throwable $throwable) {
        $errors[$themeName] = $throwable->getMessage();
        continue;
      }

      if ($result['install'] !== 'unchanged' || $result['schema'] !== 'unchanged') {
        $updatedThemes[$themeName] = $result;
      }
    }

    return [
      'inspected_count' => count($themes),
      'updated_count' => count($updatedThemes),
      'unchanged_count' => count($themes) - count($updatedThemes),
      'updated_themes' => $updatedThemes,
      'errors' => $errors,
    ];
  }

  /**
   * Repairs a single child theme's source files.
   *
   * @return array{path: string, install: string, schema: string}
   *   The file-level repair result.
   */
  private function repairTheme(Extension $theme): array {
    $themeName = $theme->getName();
    $themePath = $this->getAbsoluteThemePath($theme);

    $installPath = $themePath . '/config/install/' . $themeName . '.settings.yml';
    $schemaPath = $themePath . '/config/schema/' . $themeName . '.schema.yml';

    [$installSettings, $installExisted] = $this->loadYamlFile($installPath);
    if (!is_array($installSettings)) {
      $installSettings = [];
    }
    // Merge against the base-theme template instead of rewriting wholesale so
    // generated child themes keep any site-specific defaults they already own.
    $mergedInstallSettings = $this->mergeMissingRecursive($installSettings, $this->getInstallTemplateSettings());
    $installAction = $this->writeYamlFileIfChanged($installPath, $installSettings, $mergedInstallSettings, $installExisted);

    [$schemaDefinitions, $schemaExisted] = $this->loadYamlFile($schemaPath);
    if (!is_array($schemaDefinitions)) {
      $schemaDefinitions = [];
    }

    $schemaKey = $themeName . '.settings';
    $existingSchemaDefinition = $schemaDefinitions[$schemaKey] ?? [];
    if (!is_array($existingSchemaDefinition)) {
      $existingSchemaDefinition = [];
    }

    $templateSchemaDefinition = $this->getSchemaTemplateDefinition($theme);
    $mergedSchemaDefinition = $this->mergeMissingRecursive($existingSchemaDefinition, $templateSchemaDefinition);
    $mergedSchemaDefinitions = $schemaDefinitions;
    $mergedSchemaDefinitions[$schemaKey] = $mergedSchemaDefinition;
    $schemaAction = $this->writeYamlFileIfChanged($schemaPath, $schemaDefinitions, $mergedSchemaDefinitions, $schemaExisted);

    return [
      'path' => $theme->getPath(),
      'install' => $installAction,
      'schema' => $schemaAction,
    ];
  }

  /**
   * Returns all Emulsify-based child themes in the current codebase.
   *
   * @return array<string, \Drupal\Core\Extension\Extension>
   *   Themes keyed by machine name.
   */
  private function getEligibleThemes(): array {
    $themes = [];

    foreach ($this->themeExtensionList->getList() as $themeName => $theme) {
      if ($themeName === self::BASE_THEME) {
        continue;
      }

      if (!in_array(self::BASE_THEME, array_keys($theme->base_themes ?? []), TRUE)) {
        continue;
      }

      $themes[$themeName] = $theme;
    }

    ksort($themes);

    return $themes;
  }

  /**
   * Returns the install settings template from the base theme.
   *
   * @return array<string, mixed>
   *   The settings template.
   */
  private function getInstallTemplateSettings(): array {
    if ($this->installTemplateSettings !== NULL) {
      return $this->installTemplateSettings;
    }

    $templatePath = $this->resolveBaseThemeTemplatePath('config/install/emulsify.settings.yml');
    [$settings] = $this->loadYamlFile($templatePath, TRUE);
    if (!is_array($settings)) {
      throw new \RuntimeException(sprintf('The install template at "%s" is not a YAML mapping.', $templatePath));
    }

    return $this->installTemplateSettings = $settings;
  }

  /**
   * Returns the schema definition template for a child theme.
   *
   * @return array<string, mixed>
   *   The schema definition.
   */
  private function getSchemaTemplateDefinition(Extension $theme): array {
    if ($this->schemaTemplateDefinition === NULL) {
      $templatePath = $this->resolveBaseThemeTemplatePath('config/schema/emulsify.schema.yml');
      [$definitions] = $this->loadYamlFile($templatePath, TRUE);
      if (!is_array($definitions)) {
        throw new \RuntimeException(sprintf('The schema template at "%s" is not a YAML mapping.', $templatePath));
      }

      $definition = $definitions[self::BASE_THEME . '.settings'] ?? NULL;
      if (!is_array($definition)) {
        throw new \RuntimeException(sprintf('The schema template at "%s" is missing the "%s.settings" definition.', $templatePath, self::BASE_THEME));
      }

      $this->schemaTemplateDefinition = $definition;
    }

    $definition = $this->schemaTemplateDefinition;
    $definition['label'] = sprintf('%s settings', (string) ($theme->info['name'] ?? $theme->getName()));

    return $definition;
  }

  /**
   * Loads a YAML file if present.
   *
   * @return array{0: mixed, 1: bool}
   *   The decoded content and whether the file existed.
   */
  private function loadYamlFile(string $path, bool $required = FALSE): array {
    if (!is_file($path)) {
      if ($required) {
        throw new \RuntimeException(sprintf('Required template file "%s" was not found.', $path));
      }

      return [[], FALSE];
    }

    $contents = file_get_contents($path);
    if ($contents === FALSE) {
      throw new \RuntimeException(sprintf('Unable to read "%s".', $path));
    }

    $decoded = Yaml::decode($contents);

    return [$decoded ?? [], TRUE];
  }

  /**
   * Writes a YAML file only when the merged content changes.
   *
   * @param string $path
   *   The YAML file path.
   * @param array<string, mixed> $existing
   *   The existing file content.
   * @param array<string, mixed> $merged
   *   The merged file content.
   * @param bool $fileExisted
   *   Whether the file already existed before loading.
   */
  private function writeYamlFileIfChanged(string $path, array $existing, array $merged, bool $fileExisted): string {
    if ($merged === $existing) {
      return 'unchanged';
    }

    $this->filesystem->mkdir(dirname($path));
    $this->filesystem->dumpFile($path, Yaml::encode($merged) . "\n");

    return $fileExisted ? 'updated' : 'created';
  }

  /**
   * Fills missing array keys recursively while preserving existing values.
   */
  private function mergeMissingRecursive(mixed $existing, mixed $defaults): mixed {
    if (!is_array($defaults)) {
      return $existing ?? $defaults;
    }

    $merged = is_array($existing) ? $existing : [];

    foreach ($defaults as $key => $value) {
      if (!array_key_exists($key, $merged) || $merged[$key] === NULL) {
        $merged[$key] = $value;
        continue;
      }

      if (is_array($value) && is_array($merged[$key])) {
        // Descend only into arrays that already exist so the repair command
        // backfills missing nested keys without disturbing local overrides.
        $merged[$key] = $this->mergeMissingRecursive($merged[$key], $value);
      }
    }

    return $merged;
  }

  /**
   * Resolves a base-theme template path.
   */
  private function resolveBaseThemeTemplatePath(string $relativePath): string {
    $baseThemePath = $this->themeExtensionList->getPath(self::BASE_THEME);
    if ($baseThemePath === '') {
      throw new \RuntimeException('The Emulsify base theme could not be found.');
    }

    return $this->appRoot . '/' . trim($baseThemePath, '/\\') . '/' . ltrim($relativePath, '/\\');
  }

  /**
   * Returns the absolute filesystem path for a theme extension.
   */
  private function getAbsoluteThemePath(Extension $theme): string {
    return $this->appRoot . '/' . trim($theme->getPath(), '/\\');
  }

}
