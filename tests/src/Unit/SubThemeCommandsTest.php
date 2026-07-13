<?php

declare(strict_types=1);

namespace Drupal\Tests\emulsify_tools\Unit;

use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\emulsify_tools\Drush\Commands\SubThemeCommands;
use Drupal\emulsify_tools\ThemeGeneration\ThemeGenerationRequest;
use Drupal\emulsify_tools\ThemeGeneration\ThemeGenerationResult;
use Drupal\emulsify_tools\ThemeGeneration\ThemeGeneratorInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Tests child theme Drush command validation.
 */
#[CoversClass(SubThemeCommands::class)]
#[Group('emulsify_tools')]
final class SubThemeCommandsTest extends UnitTestCase {

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
   * Tests an explicit display-name option is passed to the generator.
   */
  public function testGenerateSubThemeUsesExplicitDisplayName(): void {
    $generator = $this->createSuccessfulGenerator();
    $command = $this->createCommand(themeGenerator: $generator);

    self::assertSame(0, $command->generateSubTheme('Happy Project', [
      'name' => 'Happy Theme',
      'description' => 'Project theme.',
    ]));

    $request = $generator->request();
    self::assertSame('happy_project', $request->machineName);
    self::assertSame('Happy Theme', $request->displayName);
    self::assertSame('Project theme.', $request->description);
    self::assertSame('whisk', $request->starterkitMachineName);
    self::assertSame('themes/custom', $request->destinationPath);
  }

  /**
   * Tests the positional value is the default display name.
   */
  public function testGenerateSubThemeDefaultsDisplayNameToArgument(): void {
    $generator = $this->createSuccessfulGenerator();
    $command = $this->createCommand(themeGenerator: $generator);

    self::assertSame(0, $command->generateSubTheme('Happy Project'));
    self::assertSame('Happy Project', $generator->request()->displayName);
  }

  /**
   * Tests explicit empty display names are forwarded without reinterpretation.
   */
  #[DataProvider('emptyDisplayNameProvider')]
  public function testGenerateSubThemePreservesEmptyDisplayName(string $displayName): void {
    $generator = $this->createSuccessfulGenerator();
    $command = $this->createCommand(themeGenerator: $generator);

    self::assertSame(0, $command->generateSubTheme('happy_theme', ['name' => $displayName]));
    self::assertSame($displayName, $generator->request()->displayName);
  }

  /**
   * Provides empty display-name options.
   *
   * @return array<string, array{string}>
   *   Display names keyed by scenario.
   */
  public static function emptyDisplayNameProvider(): array {
    return [
      'empty' => [''],
      'whitespace only' => [" \t "],
    ];
  }

  /**
   * Tests descriptions are forwarded without shell or YAML interpretation.
   */
  #[DataProvider('descriptionProvider')]
  public function testGenerateSubThemePreservesDescription(string $description): void {
    $generator = $this->createSuccessfulGenerator();
    $command = $this->createCommand(themeGenerator: $generator);

    self::assertSame(0, $command->generateSubTheme('happy_theme', ['description' => $description]));
    self::assertSame($description, $generator->request()->description);
  }

  /**
   * Provides descriptions with significant punctuation and whitespace.
   *
   * @return array<string, array{string}>
   *   Descriptions keyed by scenario.
   */
  public static function descriptionProvider(): array {
    return [
      'quotes' => ['A "quoted" theme with an apostrophe\'s metadata.'],
      'ampersand' => ['Research & Development'],
      'colon' => ['Project theme: metadata'],
      'newlines' => ["First line\nSecond line"],
      'YAML-significant characters' => ["---\nkey: [one, two]\n# comment\n*alias\n!tag"],
    ];
  }

  /**
   * Tests nonzero generator results and failure-message logging.
   */
  public function testGenerateSubThemeReturnsNonzeroExitCodeAndLogsErrors(): void {
    $logger = new SubThemeCommandRecordingLogger();
    $generator = new SubThemeCommandFakeThemeGenerator(new ThemeGenerationResult(
      7,
      ['First generation failure.', 'Second generation failure.'],
    ));
    $command = $this->createCommand(logger: $logger, themeGenerator: $generator);

    self::assertSame(7, $command->generateSubTheme('happy_theme'));
    self::assertTrue($logger->hasRecordContaining(LogLevel::ERROR, 'First generation failure.'));
    self::assertTrue($logger->hasRecordContaining(LogLevel::ERROR, 'Second generation failure.'));
  }

  /**
   * Tests generator exceptions retain their existing propagation behavior.
   */
  public function testGenerateSubThemePropagatesGeneratorException(): void {
    $exception = new \RuntimeException('Generator exploded.');
    $generator = new SubThemeCommandFakeThemeGenerator($exception);
    $command = $this->createCommand(themeGenerator: $generator);

    try {
      $command->generateSubTheme('happy_theme');
      self::fail('Expected the generator exception to be propagated.');
    }
    catch (\RuntimeException $caught) {
      self::assertSame($exception, $caught);
    }

    self::assertSame('happy_theme', $generator->request()->machineName);
  }

  /**
   * Tests successful generator messages are logged as notices.
   */
  public function testGenerateSubThemeLogsSuccessfulMessages(): void {
    $logger = new SubThemeCommandRecordingLogger();
    $themeGenerator = new SubThemeCommandFakeThemeGenerator(new ThemeGenerationResult(
      0,
      ['Theme generated successfully.'],
      'themes/custom/happy_theme',
      ['The legacy Emulsify Drupal 6.x generation path is deprecated.'],
    ));

    $command = $this->createCommand([], $logger, $themeGenerator);
    self::assertSame(0, $command->generateSubTheme('happy_theme'));
    self::assertTrue($logger->hasRecordContaining(LogLevel::NOTICE, 'Theme generated successfully.'));
    self::assertTrue($logger->hasRecordContaining(
      LogLevel::WARNING,
      'legacy Emulsify Drupal 6.x generation path is deprecated',
    ));
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
      $themeGenerator ?? $this->createSuccessfulGenerator(),
    );
    if ($logger !== NULL) {
      $command->setLogger($logger);
    }

    return $command;
  }

  /**
   * Creates a successful capturing generator fake.
   */
  private function createSuccessfulGenerator(): SubThemeCommandFakeThemeGenerator {
    return new SubThemeCommandFakeThemeGenerator(new ThemeGenerationResult(0, []));
  }

}

/**
 * Captures command requests and returns a configured result or exception.
 */
final class SubThemeCommandFakeThemeGenerator implements ThemeGeneratorInterface {

  /**
   * Captured generation request.
   */
  private ?ThemeGenerationRequest $request = NULL;

  /**
   * Creates the fake generator.
   */
  public function __construct(
    private readonly ThemeGenerationResult|\Throwable $outcome,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function generate(ThemeGenerationRequest $request): ThemeGenerationResult {
    $this->request = $request;
    if ($this->outcome instanceof \Throwable) {
      throw $this->outcome;
    }

    return $this->outcome;
  }

  /**
   * Returns the captured request.
   */
  public function request(): ThemeGenerationRequest {
    return $this->request ?? throw new \LogicException('No generation request was captured.');
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
