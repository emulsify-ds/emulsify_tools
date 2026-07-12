<?php

declare(strict_types=1);

namespace Drupal\Tests\emulsify_tools\Unit;

use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\emulsify_tools\Drush\Commands\RepairFaviconConfigCommands;
use Drupal\emulsify_tools\Favicon\ChildThemeFaviconConfigRepairer;
use Drupal\Tests\UnitTestCase;
use Drush\Attributes\Argument;
use Drush\Attributes\Command;
use Drush\Attributes\Usage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Tests the child-theme favicon config repair command.
 */
#[CoversClass(RepairFaviconConfigCommands::class)]
#[Group('emulsify_tools')]
final class RepairFaviconConfigCommandsTest extends UnitTestCase {

  /**
   * Tests command names and documented usage examples.
   */
  public function testCommandMetadata(): void {
    $method = new \ReflectionMethod(RepairFaviconConfigCommands::class, 'repairFaviconConfig');

    $commands = $method->getAttributes(Command::class);
    self::assertCount(1, $commands);
    self::assertSame('emulsify_tools:repair-favicon-config', $commands[0]->newInstance()->name);

    $arguments = $method->getAttributes(Argument::class);
    self::assertCount(1, $arguments);
    self::assertSame('theme', $arguments[0]->newInstance()->name);

    $usages = array_map(
      static fn (\ReflectionAttribute $attribute): string => $attribute->newInstance()->name,
      $method->getAttributes(Usage::class),
    );
    self::assertSame([
      'emulsify_tools:repair-favicon-config',
      'emulsify_tools:repair-favicon-config my_child_theme',
    ], $usages);
  }

  /**
   * Tests a no-op repair reports success and its unchanged summary.
   */
  public function testRepairReportsNoEligibleThemes(): void {
    $logger = new RepairFaviconConfigCommandRecordingLogger();
    $command = $this->createCommand($logger);

    self::assertSame(0, $command->repairFaviconConfig());
    self::assertTrue($logger->hasRecordContaining(
      LogLevel::NOTICE,
      'No Emulsify child theme favicon source files needed repair.',
    ));
    self::assertTrue($logger->hasRecordContaining(
      LogLevel::NOTICE,
      'Inspected 0 Emulsify-based child themes: 0 updated, 0 unchanged, 0 errors.',
    ));
  }

  /**
   * Tests an ineligible requested theme reports the repair error.
   */
  public function testRepairRejectsIneligibleRequestedTheme(): void {
    $logger = new RepairFaviconConfigCommandRecordingLogger();
    $command = $this->createCommand($logger);

    self::assertSame(1, $command->repairFaviconConfig('olivero'));
    self::assertTrue($logger->hasRecordContaining(
      LogLevel::ERROR,
      'Theme "olivero" is not an Emulsify-based child theme in this codebase.',
    ));
  }

  /**
   * Creates the command under test.
   */
  private function createCommand(RepairFaviconConfigCommandRecordingLogger $logger): RepairFaviconConfigCommands {
    $themeExtensionList = $this->createMock(ThemeExtensionList::class);
    $themeExtensionList->method('getList')->willReturn([]);

    $command = new RepairFaviconConfigCommands(new ChildThemeFaviconConfigRepairer(
      sys_get_temp_dir(),
      $themeExtensionList,
      new Filesystem(),
    ));
    $command->setLogger($logger);

    return $command;
  }

}

/**
 * Records repair command log messages.
 */
final class RepairFaviconConfigCommandRecordingLogger extends AbstractLogger {

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
    $this->records[] = [
      'level' => $level,
      'message' => (string) $message,
    ];
  }

  /**
   * Returns whether a record at the requested level contains a fragment.
   */
  public function hasRecordContaining(string $level, string $fragment): bool {
    foreach ($this->records as $record) {
      if ($record['level'] === $level && str_contains($record['message'], $fragment)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
