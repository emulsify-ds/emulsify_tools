<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools\Twig;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
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
   * Persistent cache ID prefix.
   */
  private const CACHE_PREFIX = 'emulsify_tools.theme_namespace_registry';

  /**
   * Base cache tags shared by all namespace registry entries.
   */
  private const CACHE_TAGS = ['config:core.extension', 'config:system.theme'];

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
   *
   * @param string $appRoot
   *   Drupal application root.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory.
   * @param \Drupal\Core\Extension\ModuleExtensionList $moduleExtensionList
   *   Module extension list.
   * @param \Drupal\Core\Extension\ThemeExtensionList $themeExtensionList
   *   Theme extension list.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   Logger factory.
   * @param \Drupal\Core\Theme\ThemeManagerInterface $themeManager
   *   Theme manager.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cacheBackend
   *   Persistent cache backend.
   * @param array<string, mixed> $twigConfig
   *   Twig configuration parameters.
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
    #[Autowire(service: 'cache.emulsify_tools')]
    private readonly CacheBackendInterface $cacheBackend,
    #[Autowire(param: 'twig.config')]
    private readonly array $twigConfig,
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
    if (isset($this->templatesByTheme[$themeName])) {
      return $this->templatesByTheme[$themeName];
    }

    $namespaces = $this->getNamespaces($themeName);
    if (!$this->shouldBypassPersistentCache()) {
      $cacheId = $this->getTemplateRegistryCacheId($themeName);
      $signature = $this->getTemplateRegistryCacheSignature($namespaces);
      $cachedTemplates = $this->getCachedTemplates($cacheId, $signature);
      if ($cachedTemplates !== NULL) {
        return $this->templatesByTheme[$themeName] = $cachedTemplates;
      }

      $registryData = $this->buildTemplateRegistryData($themeName, $namespaces);
      $this->cacheBackend->set($cacheId, [
        'signature' => $signature,
        'templates' => $registryData['templates'],
        'directories' => $registryData['directories'],
      ], Cache::PERMANENT, $this->getCacheTags($themeName));
      return $this->templatesByTheme[$themeName] = $registryData['templates'];
    }

    $registryData = $this->buildTemplateRegistryData($themeName, $namespaces);
    return $this->templatesByTheme[$themeName] = $registryData['templates'];
  }

  /**
   * Builds template registry data from known namespace paths.
   *
   * @param string $themeName
   *   Theme machine name.
   * @param array<string, string[]> $namespaces
   *   Namespace paths keyed by namespace name.
   *
   * @return array{templates: array<string, string>, directories: array<string, string>}
   *   Template paths and directory mtime tokens.
   */
  private function buildTemplateRegistryData(string $themeName, array $namespaces): array {
    $templates = [];
    $directories = [];

    foreach ($namespaces as $namespace => $paths) {
      foreach ($paths as $path) {
        if (!is_dir($path) || !is_readable($path)) {
          $this->logMissingPath($themeName, $namespace, $path);
          continue;
        }

        $discovery = $this->findTemplateFiles($path);
        $directories += $discovery['directories'];
        foreach ($discovery['files'] as $filePath) {
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
            $templates[$templateName] ??= $filePath;
          }
        }
      }
    }

    return [
      'templates' => $templates,
      'directories' => $directories,
    ];
  }

  /**
   * Returns a cached namespace list for a theme.
   *
   * @return array<string, string[]>
   *   A map of namespace names to filesystem paths.
   */
  private function getNamespaces(string $themeName): array {
    if (isset($this->namespacesByTheme[$themeName])) {
      return $this->namespacesByTheme[$themeName];
    }

    if (!$this->shouldBypassPersistentCache()) {
      $cacheId = $this->getNamespacesCacheId($themeName);
      $signature = $this->getNamespacesCacheSignature($themeName);
      $cachedNamespaces = $this->getCachedNamespaces($cacheId, $signature);
      if ($cachedNamespaces !== NULL) {
        return $this->namespacesByTheme[$themeName] = $cachedNamespaces;
      }

      $namespaces = $this->buildNamespaces($themeName);
      $this->cacheBackend->set($cacheId, [
        'signature' => $signature,
        'namespaces' => $namespaces,
      ], Cache::PERMANENT, $this->getCacheTags($themeName));
      return $this->namespacesByTheme[$themeName] = $namespaces;
    }

    return $this->namespacesByTheme[$themeName] = $this->buildNamespaces($themeName);
  }

  /**
   * Returns whether persistent cache should be bypassed for Twig development.
   */
  private function shouldBypassPersistentCache(): bool {
    return !empty($this->twigConfig['debug']) || !empty($this->twigConfig['auto_reload']);
  }

  /**
   * Returns a cached namespace list when the signature still matches.
   *
   * @return array<string, string[]>|null
   *   Cached namespace paths, or NULL when the item is missing or invalid.
   */
  private function getCachedNamespaces(string $cacheId, string $signature): ?array {
    $cacheItem = $this->cacheBackend->get($cacheId);
    if ($cacheItem === FALSE) {
      return NULL;
    }

    $data = $cacheItem->data ?? NULL;
    if (!is_array($data) || ($data['signature'] ?? NULL) !== $signature) {
      return NULL;
    }

    return $this->validateCachedNamespaces($data['namespaces'] ?? NULL);
  }

  /**
   * Returns a cached template registry when the signature still matches.
   *
   * @return array<string, string>|null
   *   Cached template paths, or NULL when the item is missing or invalid.
   */
  private function getCachedTemplates(string $cacheId, string $signature): ?array {
    $cacheItem = $this->cacheBackend->get($cacheId);
    if ($cacheItem === FALSE) {
      return NULL;
    }

    $data = $cacheItem->data ?? NULL;
    if (!is_array($data) || ($data['signature'] ?? NULL) !== $signature) {
      return NULL;
    }

    if (!$this->validateCachedDirectories($data['directories'] ?? NULL)) {
      return NULL;
    }

    return $this->validateCachedTemplates($data['templates'] ?? NULL);
  }

  /**
   * Validates cached namespace data before using it.
   *
   * @return array<string, string[]>|null
   *   Validated namespace data, or NULL when the shape is invalid.
   */
  private function validateCachedNamespaces(mixed $namespaces): ?array {
    if (!is_array($namespaces)) {
      return NULL;
    }

    $validated = [];
    foreach ($namespaces as $namespace => $paths) {
      if (!is_string($namespace) || !is_array($paths)) {
        return NULL;
      }

      $validatedPaths = [];
      foreach ($paths as $path) {
        if (!is_string($path)) {
          return NULL;
        }
        $validatedPaths[] = $path;
      }

      $validated[$namespace] = $validatedPaths;
    }

    return $validated;
  }

  /**
   * Validates cached template registry data before using it.
   *
   * @return array<string, string>|null
   *   Validated template registry data, or NULL when the shape is invalid.
   */
  private function validateCachedTemplates(mixed $templates): ?array {
    if (!is_array($templates)) {
      return NULL;
    }

    $validated = [];
    foreach ($templates as $template => $path) {
      if (!is_string($template) || !is_string($path)) {
        return NULL;
      }
      $validated[$template] = $path;
    }

    return $validated;
  }

  /**
   * Validates cached template directory mtime tokens before using them.
   */
  private function validateCachedDirectories(mixed $directories): bool {
    if (!is_array($directories)) {
      return FALSE;
    }

    foreach ($directories as $path => $mtime) {
      if (!is_string($path) || !is_string($mtime)) {
        return FALSE;
      }

      if ($this->getPathMtime($path) !== $mtime) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Returns the namespace cache ID for a theme.
   */
  private function getNamespacesCacheId(string $themeName): string {
    return self::CACHE_PREFIX . ':namespaces:' . $themeName;
  }

  /**
   * Returns the template registry cache ID for a theme.
   */
  private function getTemplateRegistryCacheId(string $themeName): string {
    return self::CACHE_PREFIX . ':templates:' . $themeName;
  }

  /**
   * Returns a cache signature for namespace definitions.
   */
  private function getNamespacesCacheSignature(string $themeName): string {
    $themes = $this->themeExtensionList->getList();
    $theme = $themes[$themeName] ?? NULL;
    if (!$theme instanceof Extension) {
      return 'missing';
    }

    $parts = [];
    foreach ($this->getThemeInheritanceChain($theme) as $themeExtension) {
      $parts[] = implode(':', [
        $themeExtension->getName(),
        $themeExtension->getPathname(),
        $this->getPathMtime($this->appRoot . '/' . $themeExtension->getPathname()),
      ]);
    }

    return hash('sha256', implode('|', $parts));
  }

  /**
   * Returns a cache signature for the template registry.
   *
   * @param array<string, string[]> $namespaces
   *   Namespace paths keyed by namespace name.
   */
  private function getTemplateRegistryCacheSignature(array $namespaces): string {
    $parts = [];
    foreach ($namespaces as $namespace => $paths) {
      foreach ($paths as $delta => $path) {
        $parts[] = implode(':', [
          $namespace,
          (string) $delta,
          $path,
          $this->getPathMtime($path),
        ]);
      }
    }

    return hash('sha256', implode('|', $parts));
  }

  /**
   * Returns cache tags for a theme namespace registry entry.
   *
   * @return list<string>
   *   Cache tags.
   */
  private function getCacheTags(string $themeName): array {
    $themeSettings = [];
    $themes = $this->themeExtensionList->getList();
    $theme = $themes[$themeName] ?? NULL;
    if ($theme instanceof Extension) {
      foreach ($this->getThemeInheritanceChain($theme) as $themeExtension) {
        $themeSettings[] = $themeExtension->getName() . '.settings';
      }
    }

    return Cache::mergeTags(self::CACHE_TAGS, Cache::buildTags('config', $themeSettings));
  }

  /**
   * Returns a filesystem path mtime token for cache signatures.
   */
  private function getPathMtime(string $path): string {
    if (!file_exists($path)) {
      return 'missing';
    }

    $mtime = filemtime($path);
    return $mtime === FALSE ? 'missing' : (string) $mtime;
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
      if ($this->allowsDefaultNamespaceReuse($extension)) {
        continue;
      }

      $protectedNamespaces[$extensionName] = [
        'name' => (string) ($extension->info['name'] ?? $extensionName),
        'type' => 'module',
      ];
    }

    // Themes win ties to match Drupal's existing namespace precedence.
    foreach ($this->themeExtensionList->getList() as $extensionName => $extension) {
      if ($this->allowsDefaultNamespaceReuse($extension)) {
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
   * @return array{files: string[], directories: array<string, string>}
   *   Sorted filesystem paths and directory mtime tokens.
   */
  private function findTemplateFiles(string $path): array {
    $files = [];
    $directories = [
      str_replace('\\', '/', $path) => $this->getPathMtime($path),
    ];

    try {
      $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST,
      );
    }
    catch (\UnexpectedValueException) {
      return [
        'files' => [],
        'directories' => $directories,
      ];
    }

    foreach ($iterator as $fileInfo) {
      if ($fileInfo->isDir()) {
        $directoryPath = str_replace('\\', '/', $fileInfo->getPathname());
        $directories[$directoryPath] = $this->getPathMtime($directoryPath);
        continue;
      }

      if (!$fileInfo->isFile()) {
        continue;
      }

      if (!in_array(strtolower($fileInfo->getExtension()), self::ALLOWED_FILE_EXTENSIONS, TRUE)) {
        continue;
      }

      $files[] = str_replace('\\', '/', $fileInfo->getPathname());
    }

    sort($files);
    ksort($directories);

    return [
      'files' => $files,
      'directories' => $directories,
    ];
  }

}
