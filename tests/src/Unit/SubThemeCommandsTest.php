<?php

declare(strict_types=1);

namespace Drupal\Tests\emulsify_tools\Unit;

use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\emulsify_tools\Archive\StarterRecipeArchiveExtractor;
use Drupal\emulsify_tools\Drush\Commands\SubThemeCommands;
use Drupal\emulsify_tools\Favicon\ChildThemeFaviconConfigRepairer;
use Drupal\emulsify_tools\SubThemeGenerator;
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
   * Tests a valid label still generates a child theme.
   */
  public function testGenerateSubThemeAcceptsValidLabelAndLogsMachineName(): void {
    $emulsifyPath = $this->temporaryDirectory . '/themes/contrib/emulsify';
    $this->writeStarterRecipe($emulsifyPath . '/whisk');
    $this->filesystem->mkdir($this->temporaryDirectory . '/themes/custom');

    $logger = new SubThemeCommandRecordingLogger();
    $command = $this->createCommand(['emulsify', 'stark'], $emulsifyPath, $logger);

    $workingDirectory = getcwd();
    if ($workingDirectory === FALSE) {
      throw new \RuntimeException('Unable to determine the current working directory.');
    }

    chdir($this->temporaryDirectory);
    try {
      self::assertSame(0, $command->generateSubTheme('Happy Theme'));
    }
    finally {
      chdir($workingDirectory);
    }

    $generatedInfoFile = $this->temporaryDirectory . '/themes/custom/happy_theme/happy_theme.info.yml';
    self::assertFileExists($generatedInfoFile);
    self::assertSame("name: Happy Theme\n", $this->readFile($generatedInfoFile));
    self::assertTrue($logger->hasNoticeContaining('Using "happy_theme"', 'Happy Theme'));
  }

  /**
   * Creates the command under test.
   *
   * @param string[] $existingThemes
   *   Existing theme machine names.
   * @param string|null $emulsifyPath
   *   Drupal-root-relative or absolute Emulsify theme path.
   * @param \Drupal\Tests\emulsify_tools\Unit\SubThemeCommandRecordingLogger|null $logger
   *   Optional command logger.
   */
  private function createCommand(
    array $existingThemes = [],
    ?string $emulsifyPath = NULL,
    ?SubThemeCommandRecordingLogger $logger = NULL,
  ): SubThemeCommands {
    $themeExtensionList = $this->createMock(ThemeExtensionList::class);
    $themeExtensionList->method('getList')->willReturn(array_fill_keys($existingThemes, (object) []));
    $themeExtensionList->method('getPath')
      ->willReturnCallback(static fn (string $themeName): string => $themeName === 'emulsify' ? (string) $emulsifyPath : '');

    $command = new SubThemeCommands(
      $themeExtensionList,
      new StarterRecipeArchiveExtractor($this->filesystem),
      new SubThemeGenerator($this->filesystem),
      $this->filesystem,
      new ChildThemeFaviconConfigRepairer($this->temporaryDirectory, $themeExtensionList, $this->filesystem),
    );
    if ($logger !== NULL) {
      $command->setLogger($logger);
    }

    return $command;
  }

  /**
   * Writes a minimal Whisk starter recipe.
   */
  private function writeStarterRecipe(string $directory): void {
    $this->filesystem->mkdir($directory);
    $this->writeFile($directory . '/whisk.info.emulsify.yml', "hidden: false\n");
    $this->writeFile($directory . '/whisk.info.yml', "name: EMULSIFY_NAME\n");
  }

  /**
   * Writes a test fixture file.
   */
  private function writeFile(string $path, string $contents): void {
    $result = file_put_contents($path, $contents);
    if ($result === FALSE) {
      throw new \RuntimeException(sprintf('Failed to write fixture file "%s".', $path));
    }
  }

  /**
   * Reads a generated file.
   */
  private function readFile(string $path): string {
    $contents = file_get_contents($path);
    if ($contents === FALSE) {
      throw new \RuntimeException(sprintf('Failed to read fixture file "%s".', $path));
    }

    return $contents;
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
   * Returns whether a notice contains all provided fragments.
   */
  public function hasNoticeContaining(string ...$fragments): bool {
    foreach ($this->records as $record) {
      if ($record['level'] !== LogLevel::NOTICE) {
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
