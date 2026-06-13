<?php

declare(strict_types=1);

namespace Drupal\Tests\emulsify_tools\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\emulsify_tools\AdminThemeFaviconManager;
use Drupal\emulsify_tools\Favicon\FaviconSourceSanitizerInterface;
use Drupal\emulsify_tools\Favicon\FaviconThemeSettingsBackfill;
use Drupal\emulsify_tools\Hook\AdminThemeFaviconHooks;
use Drupal\emulsify_tools\Twig\Loader\ThemeNamespaceLoader;
use Drupal\emulsify_tools\Twig\ThemeNamespaceRegistry;
use Drupal\file\FileInterface;
use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Base class for Emulsify Tools kernel tests.
 */
abstract class EmulsifyToolsKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'emulsify_tools'];

  /**
   * Runtime symlinks created for Drupal extension discovery.
   *
   * @var string[]
   */
  private static array $fixtureLinks = [];

  /**
   * {@inheritdoc}
   */
  public static function setUpBeforeClass(): void {
    parent::setUpBeforeClass();
    self::linkCoreAutoload();
    self::linkFixtureModule();
    self::linkFixtureThemes();
  }

  /**
   * {@inheritdoc}
   */
  public static function tearDownAfterClass(): void {
    self::unlinkFixtureThemes();
    parent::tearDownAfterClass();
  }

  /**
   * {@inheritdoc}
   */
  protected static function getDrupalRoot(): string {
    return dirname(__DIR__, 3) . '/vendor/drupal';
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);

    $container->register(AdminThemeFaviconManager::class, AdminThemeFaviconManager::class)
      ->setPublic(TRUE)
      ->setArguments([
        new Reference('router.admin_context'),
        new Reference('config.factory'),
        new Reference('file_url_generator'),
        new Reference('theme.manager'),
        new Reference('Drupal\Core\Extension\ThemeSettingsProvider'),
        new Reference('extension.list.theme'),
      ]);

    $container->register(AdminThemeFaviconHooks::class, AdminThemeFaviconHooks::class)
      ->setPublic(TRUE)
      ->setArguments([
        new Reference(AdminThemeFaviconManager::class),
        new Reference('current_route_match'),
      ]);

    $container->register(ThemeNamespaceRegistry::class, ThemeNamespaceRegistry::class)
      ->setPublic(TRUE)
      ->setArguments([
        '%app.root%',
        new Reference('config.factory'),
        new Reference('extension.list.module'),
        new Reference('extension.list.theme'),
        new Reference('logger.factory'),
        new Reference('theme.manager'),
        new Reference('cache.default'),
        '%twig.config%',
      ]);

    $container->register(ThemeNamespaceLoader::class, ThemeNamespaceLoader::class)
      ->setPublic(TRUE)
      ->setArguments([new Reference(ThemeNamespaceRegistry::class)])
      ->addTag('twig.loader');

    $container->register(NullFaviconSourceSanitizer::class, NullFaviconSourceSanitizer::class)
      ->setPublic(TRUE);
    $container->setAlias(FaviconSourceSanitizerInterface::class, NullFaviconSourceSanitizer::class)
      ->setPublic(TRUE);

    $container->register(FaviconThemeSettingsBackfill::class, FaviconThemeSettingsBackfill::class)
      ->setPublic(TRUE)
      ->setArguments([
        new Reference('theme_handler'),
        new Reference('config.factory'),
        new Reference('entity_type.manager'),
        new Reference(FaviconSourceSanitizerInterface::class),
      ]);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['system']);
    $this->container->get('theme_installer')->install([
      'stark',
      'emulsify',
      'emulsify_child',
      'emulsify_collision',
    ]);
    $this->container->get('extension.list.theme')->reset();
  }

  /**
   * Sets the configured frontend theme.
   */
  protected function setDefaultTheme(string $themeName): void {
    $this->config('system.theme')
      ->set('default', $themeName)
      ->save();
    $this->container->get('theme.manager')->resetActiveTheme();
  }

  /**
   * Returns an absolute path inside the Drupal root.
   */
  protected function drupalRootPath(string $path): string {
    return $this->root . '/' . ltrim($path, '/');
  }

  /**
   * Returns an absolute fixture theme path.
   */
  protected function fixtureThemePath(string $path): string {
    return $this->drupalRootPath('core/themes/custom/' . ltrim($path, '/'));
  }

  /**
   * Links fixture themes into the Drupal root so theme discovery can find them.
   */
  private static function linkFixtureThemes(): void {
    $projectRoot = dirname(__DIR__, 3);
    $targetDirectory = $projectRoot . '/vendor/drupal/core/themes/custom';
    if (!is_dir($targetDirectory)) {
      mkdir($targetDirectory, 0777, TRUE);
    }

    foreach (['emulsify', 'emulsify_child', 'emulsify_collision'] as $themeName) {
      $link = $targetDirectory . '/' . $themeName;
      $source = $projectRoot . '/tests/fixtures/themes/' . $themeName;
      if (is_link($link) || file_exists($link)) {
        continue;
      }
      symlink($source, $link);
      self::$fixtureLinks[] = $link;
    }
  }

  /**
   * Links the fixture module so Drupal can discover config schema.
   */
  private static function linkFixtureModule(): void {
    $projectRoot = dirname(__DIR__, 3);
    $targetDirectory = $projectRoot . '/vendor/drupal/core/modules/custom';
    if (!is_dir($targetDirectory)) {
      mkdir($targetDirectory, 0777, TRUE);
    }

    $link = $targetDirectory . '/emulsify_tools';
    $source = $projectRoot . '/tests/fixtures/modules/emulsify_tools';
    if (is_link($link) || file_exists($link)) {
      return;
    }

    symlink($source, $link);
    self::$fixtureLinks[] = $link;
  }

  /**
   * Links Composer's autoloader where KernelTestBase expects it.
   */
  private static function linkCoreAutoload(): void {
    $projectRoot = dirname(__DIR__, 3);
    $link = $projectRoot . '/vendor/drupal/autoload.php';
    $source = $projectRoot . '/vendor/autoload.php';
    if (is_link($link) || file_exists($link)) {
      return;
    }

    symlink($source, $link);
    self::$fixtureLinks[] = $link;
  }

  /**
   * Removes runtime fixture symlinks created by this test class.
   */
  private static function unlinkFixtureThemes(): void {
    foreach (array_reverse(self::$fixtureLinks) as $link) {
      if (is_link($link)) {
        unlink($link);
      }
    }
    self::$fixtureLinks = [];
  }

}

/**
 * Test sanitizer used when exercising backfill no-op paths.
 */
final class NullFaviconSourceSanitizer implements FaviconSourceSanitizerInterface {

  /**
   * {@inheritdoc}
   */
  public function sanitizeSourceFile(FileInterface $file): string {
    return '';
  }

}
