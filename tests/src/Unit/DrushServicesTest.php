<?php

declare(strict_types=1);

namespace Drupal\Tests\emulsify_tools\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\emulsify_tools\Drush\Commands\SubThemeCommands;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests Drush service wiring.
 */
#[CoversNothing]
#[Group('emulsify_tools')]
final class DrushServicesTest extends UnitTestCase {

  /**
   * Tests the bake command receives the services its constructor expects.
   */
  public function testSubThemeCommandServiceArguments(): void {
    $services = Yaml::decode($this->readFile(dirname(__DIR__, 3) . '/drush.services.yml'));

    self::assertIsArray($services);
    self::assertSame(SubThemeCommands::class, $services['services']['emulsify_tools.commands']['class']);
    self::assertSame([
      '@extension.list.theme',
      '@Drupal\emulsify_tools\Archive\StarterRecipeArchiveExtractor',
      '@emulsify_tools.subtheme_generator',
      '@emulsify_tools.filesystem',
      '@Drupal\emulsify_tools\Favicon\ChildThemeFaviconConfigRepairer',
    ], $services['services']['emulsify_tools.commands']['arguments']);
  }

  /**
   * Reads a fixture file.
   */
  private function readFile(string $path): string {
    $contents = file_get_contents($path);
    if ($contents === FALSE) {
      throw new \RuntimeException(sprintf('Failed to read file "%s".', $path));
    }

    return $contents;
  }

}
