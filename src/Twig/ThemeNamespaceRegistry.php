<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools\Twig;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Resolves theme-defined Twig namespaces for Emulsify themes.
 */
final class ThemeNamespaceRegistry {

  /**
   * Allowed file extensions for namespaced templates.
   */
  private const ALLOWED_FILE_EXTENSIONS = ['twig', 'html', 'svg'];

  /**
   * In-memory namespace cache keyed by active or default theme name.
   *
   * @var array<string, array<string, string[]>>
   */
  private array $namespacesByTheme = [];

  /**
   * In-memory template cache keyed by active or default theme name.
   *
   * @var array<string, array<string, string>>
   */
  private array $templatesByTheme = [];

  /**
   * Paths already warned about during the current request.
   *
   * @var array<string, bool>
   */
  private array $warnedPaths = [];

  /**
   * Namespace collisions already warned about during the current request.
   *
   * @var array<string, bool>
   */
  private array $warnedNamespaces = [];

  /**
   * Protected default namespaces keyed by namespace.
   *
   * @var array<string, array{name: string, type: string}>|null
   */
  private ?array $protectedNamespaces = NULL;

  /**
   * Creates the registry.
   */
  public function __construct(
    #[Autowire(param: 'app.root')]
    private readonly string $appRoot,
    private readonly ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'extension.list.module')]
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly ThemeExtensionList $themeExtensionList,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly ThemeManagerInterface $themeManager,
  ) {}

  /**
   * Returns the filesystem path for a namespaced template, if one exists.
   */
  public function getTemplate(string $name): ?string {
    if (!self::isValidTemplateName($name)) {
      return NULL;
    }

    foreach ($this->getCandidateThemeNames() as $themeName) {
      $registry = $this->getTemplateRegistry($themeName);
      if (isset($registry[$name])) {
        return $registry[$name];
      }
    }

    return NULL;
  }

  /**
   * Returns whether a namespaced template can be resolved.
   */
  public function hasTemplate(string $name): bool {
    return $this->getTemplate($name) !== NULL;
  }

  /**
   * Returns whether the template name can be resolved by this registry.
   */
  public static function isValidTemplateName(string $name): bool {
    if (!str_starts_with($name, '@')) {
      return FALSE;
    }

    $name = substr($name, 1);
    if ($name === '' || !str_contains($name, '/')) {
      return FALSE;
    }

    [$namespace, $path] = explode('/', $name, 2);
    if ($namespace === '' || $path === '') {
      return FALSE;
    }

    return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::ALLOWED_FILE_EXTENSIONS, TRUE);
  }

  /**
   * Returns a cached template registry for a theme.
   *
   * @return array<string, string>
   *   A map of Twig namespace references to filesystem paths.
   */
  private function getTemplateRegistry(string $themeName): array {
    return $this->templatesByTheme[$themeName] ??= $this->buildTemplateRegistry($themeName);
  }

  /**
   * Builds a template registry for a theme and its base themes.
   *
   * @return array<string, string>
   *   A map of Twig namespace references to filesystem paths.
   */
  private function buildTemplateRegistry(string $themeName): array {
    $registry = [];

    foreach ($this->getNamespaces($themeName) as $namespace => $paths) {
      foreach ($paths as $path) {
        if (!is_dir($path) || !is_readable($path)) {
          $this->logMissingPath($themeName, $namespace, $path);
          continue;
        }

        foreach ($this->findTemplateFiles($path) as $filePath) {
          $relativePath = ltrim(substr($filePath, strlen(rtrim($path, '/\\'))), '/\\');
          if ($relativePath === '') {
            continue;
          }

          $relativePath = str_replace('\\', '/', $relativePath);
          $templateNames = [
            '@' . $namespace . '/' . $relativePath,
            // Keep the basename alias for legacy includes when the filename
            // is unique, such as @components/button.twig.
            '@' . $namespace . '/' . basename($filePath),
          ];

          foreach ($templateNames as $templateName) {
            $registry[$templateName] ??= $filePath;
          }
        }
      }
    }

    return $registry;
  }

  /**
   * Returns a cached namespace list for a theme.
   *
   * @return array<string, string[]>
   *   A map of namespace names to filesystem paths.
   */
  private function getNamespaces(string $themeName): array {
    return $this->namespacesByTheme[$themeName] ??= $this->buildNamespaces($themeName);
  }

  /**
   * Builds a namespace list for a theme and its base themes.
   *
   * @return array<string, string[]>
   *   A map of namespace names to filesystem paths.
   */
  private function buildNamespaces(string $themeName): array {
    $themes = $this->themeExtensionList->getList();
    $theme = $themes[$themeName] ?? NULL;
    if (!$theme instanceof Extension) {
      return [];
    }

    $namespaces = [];
    foreach ($this->getThemeInheritanceChain($theme) as $themeExtension) {
      foreach ($this->normalizeThemeNamespaces($themeExtension) as $namespace => $paths) {
        // Child-theme paths stay ahead of base-theme paths so overrides resolve
        // in the same order Drupal template suggestions do.
        $namespaces[$namespace] = [
          ...($namespaces[$namespace] ?? []),
          ...$paths,
        ];
      }
    }

    return $namespaces;
  }

  /**
   * Returns the active theme first, followed by the default frontend theme.
   *
   * @return string[]
   *   Candidate theme machine names.
   */
  private function getCandidateThemeNames(): array {
    $themeNames = [];

    if ($this->themeManager->hasActiveTheme()) {
      $activeTheme = trim($this->themeManager->getActiveTheme()->getName());
      if ($activeTheme !== '') {
        $themeNames[] = $activeTheme;
      }
    }

    $defaultTheme = trim((string) $this->configFactory->get('system.theme')->get('default'));
    if ($defaultTheme !== '' && !in_array($defaultTheme, $themeNames, TRUE)) {
      // Admin routes typically switch the active theme, so keep the default
      // frontend theme as a fallback source of component namespaces.
      $themeNames[] = $defaultTheme;
    }

    return $themeNames;
  }

  /**
   * Returns a theme followed by its base themes in lookup order.
   *
   * @return \Drupal\Core\Extension\Extension[]
   *   Theme extensions in resolution order.
   */
  private function getThemeInheritanceChain(Extension $theme): array {
    $chain = [$theme];
    $themes = $this->themeExtensionList->getList();

    foreach (array_keys($theme->base_themes ?? []) as $baseThemeName) {
      $baseTheme = $themes[$baseThemeName] ?? NULL;
      if ($baseTheme instanceof Extension) {
        $chain[] = $baseTheme;
      }
    }

    return $chain;
  }

  /**
   * Normalizes namespace definitions from a theme's info data.
   *
   * @return array<string, string[]>
   *   A map of namespace names to filesystem paths.
   */
  private function normalizeThemeNamespaces(Extension $theme): array {
    $components = $theme->info['components'] ?? NULL;
    if (!is_array($components)) {
      return [];
    }

    $definitions = $components['namespaces'] ?? NULL;
    if (!is_array($definitions)) {
      return [];
    }

    $namespaces = [];
    foreach ($definitions as $namespace => $paths) {
      $namespace = trim((string) $namespace);
      if ($namespace === '') {
        continue;
      }

      if ($this->isProtectedNamespace($namespace, $theme->getName())) {
        $this->logProtectedNamespace($theme, $namespace);
        continue;
      }

      $normalizedPaths = $this->normalizeNamespacePaths($theme, $paths);
      if ($normalizedPaths !== []) {
        $namespaces[$namespace] = $normalizedPaths;
      }
    }

    return $namespaces;
  }

  /**
   * Normalizes namespace paths from theme info data.
   *
   * @return string[]
   *   Absolute filesystem paths.
   */
  private function normalizeNamespacePaths(Extension $theme, mixed $paths): array {
    $paths = is_array($paths) ? $paths : [$paths];
    $normalizedPaths = [];

    foreach ($paths as $path) {
      if (!is_scalar($path)) {
        continue;
      }

      $path = trim((string) $path);
      if ($path === '') {
        continue;
      }

      $normalizedPaths[] = $this->resolvePath($theme, $path);
    }

    return array_values(array_unique($normalizedPaths));
  }

  /**
   * Resolves a namespace path to an absolute filesystem path.
   */
  private function resolvePath(Extension $theme, string $path): string {
    $root = rtrim($this->appRoot, '/\\');

    if (str_starts_with($path, '/')) {
      return str_replace('\\', '/', $root . '/' . ltrim($path, '/'));
    }

    return str_replace('\\', '/', $root . '/' . trim($theme->getPath(), '/\\') . '/' . ltrim($path, '/'));
  }

  /**
   * Returns protected default namespaces keyed by namespace.
   *
   * @return array<string, array{name: string, type: string}>
   *   Protected default namespace owner metadata.
   */
  private function getProtectedNamespaces(): array {
    return $this->protectedNamespaces ??= $this->buildProtectedNamespaces();
  }

  /**
   * Builds protected default namespaces for installed modules and themes.
   *
   * Extensions may opt into reuse of their default namespace via
   * `components.allow_default_namespace_reuse` or by defining a matching
   * default namespace under `components.namespaces`.
   *
   * @return array<string, array{name: string, type: string}>
   *   Protected default namespace owner metadata.
   */
  private function buildProtectedNamespaces(): array {
    $protectedNamespaces = [];

    foreach ($this->moduleExtensionList->getList() as $extensionName => $extension) {
      if (!$extension instanceof Extension || $this->allowsDefaultNamespaceReuse($extension)) {
        continue;
      }

      $protectedNamespaces[$extensionName] = [
        'name' => (string) ($extension->info['name'] ?? $extensionName),
        'type' => 'module',
      ];
    }

    // Themes win ties to match Drupal's existing namespace precedence.
    foreach ($this->themeExtensionList->getList() as $extensionName => $extension) {
      if (!$extension instanceof Extension || $this->allowsDefaultNamespaceReuse($extension)) {
        continue;
      }

      $protectedNamespaces[$extensionName] = [
        'name' => (string) ($extension->info['name'] ?? $extensionName),
        'type' => 'theme',
      ];
    }

    return $protectedNamespaces;
  }

  /**
   * Returns whether an extension allows reuse of its default namespace.
   */
  private function allowsDefaultNamespaceReuse(Extension $extension): bool {
    $components = $extension->info['components'] ?? NULL;
    if (!is_array($components)) {
      return FALSE;
    }

    // Mirror drupal/components, where presence of the key opts in.
    if (array_key_exists('allow_default_namespace_reuse', $components)) {
      return TRUE;
    }

    $definitions = $components['namespaces'] ?? NULL;
    return is_array($definitions) && !empty($definitions[$extension->getName()]);
  }

  /**
   * Returns whether the namespace would shadow a protected default namespace.
   */
  private function isProtectedNamespace(string $namespace, string $definingThemeName): bool {
    if ($namespace === $definingThemeName) {
      return FALSE;
    }

    return isset($this->getProtectedNamespaces()[$namespace]);
  }

  /**
   * Logs a protected namespace collision once per request.
   */
  private function logProtectedNamespace(Extension $theme, string $namespace): void {
    $key = $theme->getName() . ':' . $namespace;
    if (isset($this->warnedNamespaces[$key])) {
      return;
    }
    $this->warnedNamespaces[$key] = TRUE;

    [$ownerType, $ownerName] = $this->getProtectedNamespaceOwner($namespace);
    $this->loggerFactory->get('emulsify_tools')->warning(sprintf(
      'The "%s" theme attempted to reuse the protected Twig namespace "@%s", which is owned by the %s "%s". Choose a custom namespace name instead.',
      $theme->getName(),
      $namespace,
      $ownerType,
      $ownerName,
    ));
  }

  /**
   * Logs an invalid namespace path once per request.
   */
  private function logMissingPath(string $themeName, string $namespace, string $path): void {
    $key = $themeName . ':' . $namespace . ':' . $path;
    if (isset($this->warnedPaths[$key])) {
      return;
    }
    $this->warnedPaths[$key] = TRUE;

    $this->loggerFactory->get('emulsify_tools')->warning(sprintf(
      'The "@%s" Twig namespace defined by the "%s" theme points to "%s", which is not a readable directory.',
      $namespace,
      $themeName,
      $path,
    ));
  }

  /**
   * Returns the owner info for a protected namespace.
   *
   * @return array{0: string, 1: string}
   *   The owner type and human-readable name.
   */
  private function getProtectedNamespaceOwner(string $namespace): array {
    $owner = $this->getProtectedNamespaces()[$namespace] ?? NULL;
    if (is_array($owner)) {
      return [$owner['type'], $owner['name']];
    }

    return ['extension', $namespace];
  }

  /**
   * Returns all supported template files in a namespace directory.
   *
   * @return string[]
   *   Sorted filesystem paths.
   */
  private function findTemplateFiles(string $path): array {
    $files = [];

    try {
      $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
      );
    }
    catch (\UnexpectedValueException) {
      return [];
    }

    foreach ($iterator as $fileInfo) {
      if (!$fileInfo->isFile()) {
        continue;
      }

      if (!in_array(strtolower($fileInfo->getExtension()), self::ALLOWED_FILE_EXTENSIONS, TRUE)) {
        continue;
      }

      $files[] = str_replace('\\', '/', $fileInfo->getPathname());
    }

    sort($files);

    return $files;
  }

}
