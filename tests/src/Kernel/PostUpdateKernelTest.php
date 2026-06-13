<?php

declare(strict_types=1);

namespace Drupal\Tests\emulsify_tools\Kernel;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests post-update integration paths.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
final class PostUpdateKernelTest extends EmulsifyToolsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    putenv('EMULSIFY_TOOLS_DISABLE_EMULSIFY_FIXTURES=1');
    $this->disableEmulsifyFaviconComposerAutoload();
    parent::setUp();
    $this->disableEmulsifyFaviconComposerAutoload();
    require_once dirname(__DIR__, 3) . '/emulsify_tools.post_update.php';
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('EMULSIFY_TOOLS_DISABLE_EMULSIFY_FIXTURES');
    parent::tearDown();
  }

  /**
   * Tests the backfill post-update is safe without Emulsify 7 favicon classes.
   */
  public function testBackfillPostUpdateNoOpsWhenEmulsifyFaviconSettingsClassIsAbsent(): void {
    self::assertFalse(class_exists('Drupal\emulsify\Favicon\FaviconSettings'));

    $sandbox = [];
    $message = emulsify_tools_post_update_backfill_emulsify_favicon_settings($sandbox);

    self::assertStringContainsString('Backfilled favicon settings for 0 of 0', $message);
  }

  /**
   * Removes only the Emulsify favicon fixture namespace from Composer autoload.
   */
  private function disableEmulsifyFaviconComposerAutoload(): void {
    foreach (spl_autoload_functions() as $loader) {
      if (!is_array($loader) || !$loader[0] instanceof ClassLoader) {
        continue;
      }
      $loader[0]->setPsr4('Drupal\\emulsify\\Favicon\\', []);
    }
  }

}
