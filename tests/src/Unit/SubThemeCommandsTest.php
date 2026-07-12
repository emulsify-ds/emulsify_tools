<?php

declare(strict_types=1);

namespace Drupal\Tests\emulsify_tools\Unit;

use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\emulsify_tools\Drush\Commands\SubThemeCommands;
use Drupal\emulsify_tools\Favicon\ChildThemeFaviconConfigRepairer;
use Drupal\emulsify_tools\ThemeGeneration\ThemeGenerationRequest;
use Drupal\emulsify_tools\ThemeGeneration\ThemeGenerationResult;
use Drupal\emulsify_tools\ThemeGeneration\ThemeGeneratorInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Tests child theme Drush command validation.
 */
#[CoversClass(SubThemeCommands::class)]
#[Group('emulsify_tools')]
final class SubThemeCommandsTest extends UnitTestCase {

  /**
   * Filesystem helper.
   */
  private Filesystem $filesystem;

  /**
   * Temporary fixture directory.
   */
  private string $temporaryDirectory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->filesystem = new Filesystem();
    $this->temporaryDirectory = sys_get_temp_dir() . '/emulsify_tools_command_' . bin2hex(random_bytes(8));
    $this->filesystem->mkdir($this->temporaryDirectory);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (isset($this->temporaryDirectory) && $this->filesystem->exists($this->temporaryDirectory)) {
      $this->filesystem->remove($this->temporaryDirectory);
    }

    parent::tearDown();
  }

  /**
   * Tests leading-digit names are rejected.
   */
  public function testGenerateSubThemeRejectsLeadingDigitMachineName(): void {
    $command = $this->createCommand();

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('must start with a lowercase letter');
    $command->generateSubTheme('123 Theme');
  }

  /**
   * Tests names over Drupal's extension-name length limit are rejected.
   */
  public function testGenerateSubThemeRejectsTooLongMachineName(): void {
    $command = $this->createCommand();

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage(sprintf(
      'must be %d characters or fewer',
      \DRUPAL_EXTENSION_NAME_MAX_LENGTH,
    ));
    $command->generateSubTheme(str_repeat('a', \DRUPAL_EXTENSION_NAME_MAX_LENGTH + 1));
  }

  /**
   * Tests the Emulsify base theme machine name is reserved.
   */
  public function testGenerateSubThemeRejectsEmulsifyBaseThemeName(): void {
    $command = $this->createCommand();

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('reserved by the Emulsify base theme');
    $command->generateSubTheme('emulsify');
  }

  /**
   * Tests names that collide with existing themes are rejected.
   */
  public function testGenerateSubThemeRejectsExistingThemeName(): void {
    $command = $this->createCommand(['stark']);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('already used by an existing Drupal theme');
    $command->generateSubTheme('Stark');
  }

  /**
   * Tests generation is delegated with the exact resolved request values.
   */
  public function testGenerateSubThemeDelegatesToThemeGenerator(): void {
    $description = 'Project theme: punctuation & metadata.';
    $logger = new SubThemeCommandRecordingLogger();
    $themeGenerator = $this->createMock(ThemeGeneratorInterface::class);
    $themeGenerator->expects($this->once())
      ->method('generate')
      ->with(self::callback(static function (ThemeGenerationRequest $request) use ($description): bool {
        self::assertSame('happy_project', $request->machineName);
        self::assertSame('Happy Theme', $request->displayName);
        self::assertSame($description, $request->description);
        self::assertSame('whisk', $request->starterkitMachineName);
        self::assertSame('themes/custom', $request->destinationPath);
        return TRUE;
      }))
      ->willReturn(new ThemeGenerationResult(
        7,
        ['Theme generation failed.'],
        'themes/custom/happy_project',
      ));

    $command = $this->createCommand(['emulsify', 'stark'], $logger, $themeGenerator);
    self::assertSame(7, $command->generateSubTheme('Happy Project', [
      'name' => 'Happy Theme',
      'description' => $description,
    ]));

    self::assertTrue($logger->hasRecordContaining(LogLevel::NOTICE, 'Using "happy_project"', 'Happy Project'));
    self::assertTrue($logger->hasRecordContaining(LogLevel::ERROR, 'Theme generation failed.'));
  }

  /**
   * Tests successful generator messages are logged as notices.
   */
  public function testGenerateSubThemeLogsSuccessfulMessages(): void {
    $logger = new SubThemeCommandRecordingLogger();
    $themeGenerator = $this->createMock(ThemeGeneratorInterface::class);
    $themeGenerator->expects($this->once())
      ->method('generate')
      ->willReturn(new ThemeGenerationResult(
        0,
        ['Theme generated successfully.'],
        'themes/custom/happy_theme',
      ));

    $command = $this->createCommand([], $logger, $themeGenerator);
    self::assertSame(0, $command->generateSubTheme('happy_theme'));
    self::assertTrue($logger->hasRecordContaining(LogLevel::NOTICE, 'Theme generated successfully.'));
  }

  /**
   * Creates the command under test.
   *
   * @param string[] $existingThemes
   *   Existing theme machine names.
   * @param \Drupal\Tests\emulsify_tools\Unit\SubThemeCommandRecordingLogger|null $logger
   *   Optional command logger.
   * @param \Drupal\emulsify_tools\ThemeGeneration\ThemeGeneratorInterface|null $themeGenerator
   *   Optional theme generator.
   */
  private function createCommand(
    array $existingThemes = [],
    ?SubThemeCommandRecordingLogger $logger = NULL,
    ?ThemeGeneratorInterface $themeGenerator = NULL,
  ): SubThemeCommands {
    $themeExtensionList = $this->createMock(ThemeExtensionList::class);
    $themeExtensionList->method('getList')->willReturn(array_fill_keys($existingThemes, (object) []));

    $command = new SubThemeCommands(
      $themeExtensionList,
      new ChildThemeFaviconConfigRepairer($this->temporaryDirectory, $themeExtensionList, $this->filesystem),
      $themeGenerator ?? $this->createMock(ThemeGeneratorInterface::class),
    );
    if ($logger !== NULL) {
      $command->setLogger($logger);
    }

    return $command;
  }

}

/**
 * Records command log messages.
 */
final class SubThemeCommandRecordingLogger extends AbstractLogger {

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
   * Returns whether a record at the requested level contains all fragments.
   */
  public function hasRecordContaining(string $level, string ...$fragments): bool {
    foreach ($this->records as $record) {
      if ($record['level'] !== $level) {
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
