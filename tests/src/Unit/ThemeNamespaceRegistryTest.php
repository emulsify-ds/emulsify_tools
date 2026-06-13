<?php

declare(strict_types=1);

namespace Drupal\Tests\emulsify_tools\Unit;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\emulsify_tools\Twig\ThemeNamespaceRegistry;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests Twig namespace template validation.
 */
#[CoversClass(ThemeNamespaceRegistry::class)]
#[Group('emulsify_tools')]
final class ThemeNamespaceRegistryTest extends UnitTestCase {

  /**
   * Temporary fixture root.
   */
  private ?string $fixtureRoot = NULL;

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if ($this->fixtureRoot !== NULL) {
      $this->removeDirectory($this->fixtureRoot);
      $this->fixtureRoot = NULL;
    }

    parent::tearDown();
  }

  /**
   * Tests supported and unsupported template names.
   *
   * @param string $templateName
   *   The template name to validate.
   * @param bool $expected
   *   The expected validation result.
   */
  #[DataProvider('providerTemplateNames')]
  public function testIsValidTemplateName(string $templateName, bool $expected): void {
    self::assertSame($expected, ThemeNamespaceRegistry::isValidTemplateName($templateName));
  }

  /**
   * Tests namespace and template registries are written on cache miss.
   */
  public function testPersistentCacheMissRebuildsRegistry(): void {
    $theme = $this->createFixtureTheme();
    $cacheBackend = new RecordingNamespaceCacheBackend();
    $registry = $this->createRegistry($theme, $cacheBackend);

    self::assertSame($this->themePath('components/card.twig'), $registry->getTemplate('@ui/card.twig'));
    self::assertSame(2, $cacheBackend->setCount());
    self::assertSame([
      'emulsify_tools.theme_namespace_registry:namespaces:cached_theme',
      'emulsify_tools.theme_namespace_registry:templates:cached_theme',
    ], $cacheBackend->setCacheIds());
    self::assertContains('config:core.extension', $cacheBackend->tagsFor('emulsify_tools.theme_namespace_registry:namespaces:cached_theme'));
    self::assertContains('config:system.theme', $cacheBackend->tagsFor('emulsify_tools.theme_namespace_registry:namespaces:cached_theme'));
    self::assertContains('config:cached_theme.settings', $cacheBackend->tagsFor('emulsify_tools.theme_namespace_registry:namespaces:cached_theme'));
  }

  /**
   * Tests a second request can reuse the persistent cache.
   */
  public function testPersistentCacheHitReusesRegistry(): void {
    $theme = $this->createFixtureTheme();
    $cacheBackend = new RecordingNamespaceCacheBackend();
    $registry = $this->createRegistry($theme, $cacheBackend);

    self::assertSame($this->themePath('components/card.twig'), $registry->getTemplate('@ui/card.twig'));

    $cacheBackend->resetOperations();
    $registry = $this->createRegistry($theme, $cacheBackend);

    self::assertSame($this->themePath('components/card.twig'), $registry->getTemplate('@ui/card.twig'));
    self::assertSame(0, $cacheBackend->setCount());
    self::assertSame([
      'emulsify_tools.theme_namespace_registry:namespaces:cached_theme',
      'emulsify_tools.theme_namespace_registry:templates:cached_theme',
    ], $cacheBackend->getCacheIds());
  }

  /**
   * Tests Twig development settings bypass persistent cache.
   *
   * @param array<string, mixed> $twigConfig
   *   Twig configuration parameters.
   */
  #[DataProvider('providerDevelopmentTwigConfig')]
  public function testTwigDevelopmentConfigBypassesPersistentCache(array $twigConfig): void {
    $theme = $this->createFixtureTheme();
    $cacheBackend = new RecordingNamespaceCacheBackend();
    $registry = $this->createRegistry($theme, $cacheBackend);

    self::assertSame($this->themePath('components/card.twig'), $registry->getTemplate('@ui/card.twig'));
    $this->writeThemeFile('components/nested/new.twig');

    $cacheBackend->resetOperations();
    $registry = $this->createRegistry($theme, $cacheBackend, $twigConfig);

    self::assertSame($this->themePath('components/nested/new.twig'), $registry->getTemplate('@ui/nested/new.twig'));
    self::assertSame(0, $cacheBackend->getCount());
    self::assertSame(0, $cacheBackend->setCount());
  }

  /**
   * Provides template names for validation tests.
   *
   * @return array<string, array{0: string, 1: bool}>
   *   The template name and expected result.
   */
  public static function providerTemplateNames(): array {
    return [
      'twig template' => ['@atoms/button.twig', TRUE],
      'uppercase extension' => ['@atoms/button.TWIG', TRUE],
      'html template' => ['@atoms/cards/card.html', TRUE],
      'svg template' => ['@icons/close.svg', TRUE],
      'missing namespace prefix' => ['atoms/button.twig', FALSE],
      'missing slash' => ['@atoms', FALSE],
      'missing namespace name' => ['@/button.twig', FALSE],
      'missing template path' => ['@atoms/', FALSE],
      'missing extension' => ['@atoms/button', FALSE],
      'unsupported extension' => ['@atoms/button.php', FALSE],
    ];
  }

  /**
   * Provides Twig development configurations that should bypass cache.
   *
   * @return array<string, array{0: array<string, mixed>}>
   *   Twig configuration parameter values.
   */
  public static function providerDevelopmentTwigConfig(): array {
    return [
      'debug' => [['debug' => TRUE, 'auto_reload' => NULL]],
      'auto reload' => [['debug' => FALSE, 'auto_reload' => TRUE]],
    ];
  }

  /**
   * Creates the registry under test.
   *
   * @param \Drupal\Core\Extension\Extension $theme
   *   Theme extension fixture.
   * @param \Drupal\Tests\emulsify_tools\Unit\RecordingNamespaceCacheBackend $cacheBackend
   *   Recording cache backend.
   * @param array<string, mixed> $twigConfig
   *   Twig configuration parameters.
   */
  private function createRegistry(
    Extension $theme,
    RecordingNamespaceCacheBackend $cacheBackend,
    array $twigConfig = ['debug' => FALSE, 'auto_reload' => NULL],
  ): ThemeNamespaceRegistry {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('default')
      ->willReturn($theme->getName());

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('system.theme')
      ->willReturn($config);

    $moduleExtensionList = $this->createMock(ModuleExtensionList::class);
    $moduleExtensionList->method('getList')->willReturn([]);

    $themeExtensionList = $this->createMock(ThemeExtensionList::class);
    $themeExtensionList->method('getList')->willReturn([$theme->getName() => $theme]);

    $themeManager = $this->createMock(ThemeManagerInterface::class);
    $themeManager->method('hasActiveTheme')->willReturn(FALSE);

    return new ThemeNamespaceRegistry(
      $this->fixtureRoot ?? '',
      $configFactory,
      $moduleExtensionList,
      $themeExtensionList,
      $this->createMock(LoggerChannelFactoryInterface::class),
      $themeManager,
      $cacheBackend,
      $twigConfig,
    );
  }

  /**
   * Creates a fixture theme extension.
   */
  private function createFixtureTheme(): Extension {
    $this->fixtureRoot = sys_get_temp_dir() . '/emulsify_tools_registry_' . bin2hex(random_bytes(8));
    mkdir($this->themePath('components/nested'), 0777, TRUE);
    $this->writeThemeFile('cached_theme.info.yml', "name: Cached Theme\n");
    $this->writeThemeFile('components/card.twig');

    $theme = new Extension($this->fixtureRoot, 'theme', 'themes/custom/cached_theme/cached_theme.info.yml');
    $theme->info = [
      'name' => 'Cached Theme',
      'type' => 'theme',
      'components' => [
        'namespaces' => [
          'ui' => 'components',
        ],
      ],
    ];

    return $theme;
  }

  /**
   * Writes a fixture theme file.
   */
  private function writeThemeFile(string $path, string $contents = ''): void {
    $filePath = $this->themePath($path);
    $directory = dirname($filePath);
    if (!is_dir($directory)) {
      mkdir($directory, 0777, TRUE);
    }

    file_put_contents($filePath, $contents);
  }

  /**
   * Returns an absolute path inside the fixture theme.
   */
  private function themePath(string $path): string {
    return ($this->fixtureRoot ?? '') . '/themes/custom/cached_theme/' . ltrim($path, '/');
  }

  /**
   * Recursively removes a temporary directory.
   */
  private function removeDirectory(string $path): void {
    if (!is_dir($path)) {
      return;
    }

    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $fileInfo) {
      if ($fileInfo->isDir()) {
        rmdir($fileInfo->getPathname());
        continue;
      }
      unlink($fileInfo->getPathname());
    }
    rmdir($path);
  }

}

/**
 * Recording cache backend for namespace registry unit tests.
 */
final class RecordingNamespaceCacheBackend implements CacheBackendInterface {

  /**
   * Cached items keyed by cache ID.
   *
   * @var array<string, object>
   */
  private array $items = [];

  /**
   * Cache IDs read since the last reset.
   *
   * @var string[]
   */
  private array $getCacheIds = [];

  /**
   * Cache set calls since the last reset.
   *
   * @var list<array{cid: string, data: mixed, expire: int, tags: list<string>}>
   */
  private array $setCalls = [];

  /**
   * {@inheritdoc}
   */
  public function get($cid, $allow_invalid = FALSE) {
    $cid = (string) $cid;
    $this->getCacheIds[] = $cid;
    $item = $this->items[$cid] ?? NULL;
    if ($item === NULL || (!$allow_invalid && empty($item->valid))) {
      return FALSE;
    }

    return $item;
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(&$cids, $allow_invalid = FALSE) {
    $items = [];
    foreach ($cids as $key => $cid) {
      $item = $this->get($cid, $allow_invalid);
      if ($item === FALSE) {
        continue;
      }
      $items[$cid] = $item;
      unset($cids[$key]);
    }

    return $items;
  }

  /**
   * {@inheritdoc}
   */
  public function set($cid, $data, $expire = Cache::PERMANENT, array $tags = []) {
    $cid = (string) $cid;
    $tags = array_values($tags);
    $item = (object) [
      'cid' => $cid,
      'data' => $data,
      'expire' => $expire,
      'tags' => $tags,
      'valid' => TRUE,
    ];
    $this->items[$cid] = $item;
    $this->setCalls[] = [
      'cid' => $cid,
      'data' => $data,
      'expire' => $expire,
      'tags' => $tags,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setMultiple(array $items) {
    foreach ($items as $cid => $item) {
      $this->set(
        $cid,
        $item['data'],
        $item['expire'] ?? Cache::PERMANENT,
        $item['tags'] ?? [],
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delete($cid) {
    unset($this->items[(string) $cid]);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteMultiple(array $cids) {
    foreach ($cids as $cid) {
      $this->delete($cid);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAll() {
    $this->items = [];
  }

  /**
   * {@inheritdoc}
   */
  public function invalidate($cid) {
    $cid = (string) $cid;
    if (isset($this->items[$cid])) {
      $this->items[$cid]->valid = FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateMultiple(array $cids) {
    foreach ($cids as $cid) {
      $this->invalidate($cid);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateAll() {
    foreach ($this->items as $item) {
      $item->valid = FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function garbageCollection() {
  }

  /**
   * {@inheritdoc}
   */
  public function removeBin() {
    $this->deleteAll();
  }

  /**
   * Clears operation counters.
   */
  public function resetOperations(): void {
    $this->getCacheIds = [];
    $this->setCalls = [];
  }

  /**
   * Returns the number of cache get calls.
   */
  public function getCount(): int {
    return count($this->getCacheIds);
  }

  /**
   * Returns the number of cache set calls.
   */
  public function setCount(): int {
    return count($this->setCalls);
  }

  /**
   * Returns read cache IDs.
   *
   * @return string[]
   *   Cache IDs.
   */
  public function getCacheIds(): array {
    return $this->getCacheIds;
  }

  /**
   * Returns written cache IDs.
   *
   * @return string[]
   *   Cache IDs.
   */
  public function setCacheIds(): array {
    return array_map(
      static fn (array $call): string => $call['cid'],
      $this->setCalls,
    );
  }

  /**
   * Returns tags written for a cache ID.
   *
   * @return list<string>
   *   Cache tags.
   */
  public function tagsFor(string $cid): array {
    foreach ($this->setCalls as $call) {
      if ($call['cid'] === $cid) {
        return $call['tags'];
      }
    }

    return [];
  }

}
