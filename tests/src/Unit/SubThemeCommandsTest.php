<?php

declare(strict_types=1);

namespace Drupal\Tests\emulsify_tools\Unit;

use Drupal\Core\Command\GenerateTheme;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\emulsify_tools\Drush\Commands\SubThemeCommands;
use Drupal\emulsify_tools\Favicon\ChildThemeFaviconConfigRepairer;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
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
   * Tests Drush and Drupal core generate byte-identical child themes.
   */
  public function testGenerateSubThemeMatchesDrupalCoreStarterkit(): void {
    $this->writeStarterRecipe($this->temporaryDirectory . '/themes/contrib/emulsify/whisk');
    $description = 'Project theme: punctuation & metadata.';

    $this->generateWithCore(
      'happy_theme',
      'Happy Theme',
      $description,
      'themes/core-generated',
    );

    $logger = new SubThemeCommandRecordingLogger();
    $command = $this->createCommand(['emulsify', 'stark'], $this->temporaryDirectory, $logger);
    self::assertSame(0, $command->generateSubTheme('Happy Theme', [
      'description' => $description,
    ]));

    self::assertSame(
      $this->readDirectory($this->temporaryDirectory . '/themes/core-generated/happy_theme'),
      $this->readDirectory($this->temporaryDirectory . '/themes/custom/happy_theme'),
    );
    self::assertTrue($logger->hasNoticeContaining('Using "happy_theme"', 'Happy Theme'));
  }

  /**
   * Creates the command under test.
   *
   * @param string[] $existingThemes
   *   Existing theme machine names.
   * @param string|null $appRoot
   *   Drupal application root.
   * @param \Drupal\Tests\emulsify_tools\Unit\SubThemeCommandRecordingLogger|null $logger
   *   Optional command logger.
   */
  private function createCommand(
    array $existingThemes = [],
    ?string $appRoot = NULL,
    ?SubThemeCommandRecordingLogger $logger = NULL,
  ): SubThemeCommands {
    $themeExtensionList = $this->createMock(ThemeExtensionList::class);
    $themeExtensionList->method('getList')->willReturn(array_fill_keys($existingThemes, (object) []));

    $command = new SubThemeCommands(
      $themeExtensionList,
      new ChildThemeFaviconConfigRepairer($this->temporaryDirectory, $themeExtensionList, $this->filesystem),
      $appRoot ?? $this->temporaryDirectory,
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
    $this->writeFile($directory . '/whisk.info.yml', <<<YAML
name: Whisk Starter
type: theme
base theme: false
core_version_requirement: '^11.3 || ^12'
version: 1.0.0
YAML . "\n");
    $this->writeFile($directory . '/whisk.starterkit.yml', "info: {}\n");
    $this->writeFile($directory . '/README.md', "Whisk Starter (whisk)\n");
  }

  /**
   * Generates the comparison theme through Drupal core directly.
   */
  private function generateWithCore(
    string $machineName,
    string $name,
    string $description,
    string $path,
  ): void {
    $input = new ArrayInput([
      'machine-name' => $machineName,
      '--name' => $name,
      '--description' => $description,
      '--starterkit' => 'whisk',
      '--path' => $path,
    ]);
    $input->setInteractive(FALSE);
    $workingDirectory = getcwd();

    try {
      self::assertSame(0, (new GenerateTheme(NULL, $this->temporaryDirectory))->run(
        $input,
        new BufferedOutput(),
      ));
    }
    finally {
      if ($workingDirectory !== FALSE) {
        chdir($workingDirectory);
      }
    }
  }

  /**
   * Reads every directory and file in a generated theme.
   *
   * @return array<string, string>
   *   Content keyed by relative path, including directory markers.
   */
  private function readDirectory(string $directory): array {
    $contents = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::SELF_FIRST,
    );
    $prefixLength = strlen(rtrim($directory, DIRECTORY_SEPARATOR)) + 1;

    foreach ($iterator as $file) {
      $relativePath = substr($file->getPathname(), $prefixLength);
      $contents[$relativePath] = $file->isDir()
        ? 'directory'
        : 'file:' . $this->readFile($file->getPathname());
    }

    ksort($contents);
    return $contents;
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
