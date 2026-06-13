<?php

declare(strict_types=1);

namespace Drupal\Tests\emulsify_tools\Kernel;

use Drupal\Core\Logger\RfcLogLevel;
use Drupal\emulsify_tools\Twig\Loader\ThemeNamespaceLoader;
use Drupal\emulsify_tools\Twig\ThemeNamespaceRegistry;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\AbstractLogger;

/**
 * Tests theme-declared Twig namespaces in a real Drupal kernel.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
final class ThemeNamespaceKernelTest extends EmulsifyToolsKernelTestBase {

  /**
   * Tests namespace resolution and base theme inheritance.
   */
  public function testThemeNamespacesResolveDeclaredTemplatesAndAliases(): void {
    $this->setDefaultTheme('emulsify_child');

    $registry = $this->container->get(ThemeNamespaceRegistry::class);
    $loader = $this->container->get(ThemeNamespaceLoader::class);

    $childTemplate = $this->fixtureThemePath('emulsify_child/components/card.twig');
    $childSharedTemplate = $this->fixtureThemePath('emulsify_child/components/shared.twig');
    $baseOnlyTemplate = $this->fixtureThemePath('emulsify/components/base-only.twig');
    $uniqueAliasTemplate = $this->fixtureThemePath('emulsify_child/components/nested/unique.twig');

    self::assertSame($childTemplate, $registry->getTemplate('@ui/card.twig'));
    self::assertSame($childTemplate, $loader->getSourceContext('@ui/card.twig')->getPath());
    self::assertTrue($loader->exists('@ui/card.twig'));

    self::assertSame($uniqueAliasTemplate, $registry->getTemplate('@ui/unique.twig'));
    self::assertSame($uniqueAliasTemplate, $loader->getSourceContext('@ui/unique.twig')->getPath());

    self::assertSame($childSharedTemplate, $registry->getTemplate('@ui/shared.twig'));
    self::assertSame($baseOnlyTemplate, $registry->getTemplate('@ui/base-only.twig'));
  }

  /**
   * Tests that protected Drupal namespaces cannot be reused by themes.
   */
  public function testProtectedDefaultNamespaceCollisionIsRejectedAndLogged(): void {
    $this->setDefaultTheme('emulsify_collision');
    $logger = new RecordingLogger();
    $this->container->get('logger.factory')->addLogger($logger);

    $registry = $this->container->get(ThemeNamespaceRegistry::class);
    $loader = $this->container->get(ThemeNamespaceLoader::class);

    self::assertNull($registry->getTemplate('@system/collision.twig'));
    self::assertFalse($loader->exists('@system/collision.twig'));
    self::assertTrue($logger->hasWarningContaining('@system', 'emulsify_collision'));
  }

}

/**
 * Logger that records messages emitted through Drupal logger channels.
 */
final class RecordingLogger extends AbstractLogger {

  /**
   * Recorded log entries.
   *
   * @var list<array{level: mixed, message: string}>
   */
  private array $records = [];

  /**
   * {@inheritdoc}
   */
  public function log($level, \Stringable|string $message, array $context = []): void {
    $message = strtr((string) $message, array_map(
      static fn (mixed $value): string => (string) $value,
      $context,
    ));
    $this->records[] = [
      'level' => $level,
      'message' => $message,
    ];
  }

  /**
   * Returns whether a warning contains all provided fragments.
   */
  public function hasWarningContaining(string ...$fragments): bool {
    foreach ($this->records as $record) {
      if ($record['level'] !== 'warning' && $record['level'] !== RfcLogLevel::WARNING) {
        continue;
      }
      foreach ($fragments as $fragment) {
        if (!str_contains($record['message'], $fragment)) {
          continue 2;
        }
      }
      return TRUE;
    }

    return FALSE;
  }

}
