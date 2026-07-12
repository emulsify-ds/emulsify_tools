<?php

declare(strict_types=1);

namespace Drupal\Tests\emulsify_tools\Unit\ThemeGeneration;

use Drupal\Core\Command\GenerateTheme;
use Drupal\emulsify_tools\ThemeGeneration\DrupalStarterkitThemeGenerator;
use Drupal\emulsify_tools\ThemeGeneration\ThemeGenerationRequest;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Tests the Drupal Starterkit theme generator integration.
 */
#[CoversClass(DrupalStarterkitThemeGenerator::class)]
#[Group('emulsify_tools')]
final class DrupalStarterkitThemeGeneratorTest extends UnitTestCase {

  /**
   * Filesystem helper.
   */
  private Filesystem $filesystem;

  /**
   * Temporary fixture directory.
   */
  private string $temporaryDirectory;

  /**
   * Temporary Drupal application root.
   */
  private string $appRoot;

  /**
   * Working directory used by the generator caller.
   */
  private string $callerDirectory;

  /**
   * Test suite working directory.
   */
  private string $originalWorkingDirectory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->filesystem = new Filesystem();
    $originalWorkingDirectory = getcwd();
    if ($originalWorkingDirectory === FALSE) {
      throw new \RuntimeException('Unable to determine the test working directory.');
    }
    $this->originalWorkingDirectory = $originalWorkingDirectory;

    $this->temporaryDirectory = sys_get_temp_dir() . '/emulsify_tools_generator_' . bin2hex(random_bytes(8));
    $this->appRoot = $this->temporaryDirectory . '/app-root';
    $callerDirectory = $this->temporaryDirectory . '/caller';
    $this->filesystem->mkdir([$this->appRoot, $callerDirectory]);

    $resolvedCallerDirectory = realpath($callerDirectory);
    if ($resolvedCallerDirectory === FALSE || !chdir($resolvedCallerDirectory)) {
      throw new \RuntimeException('Unable to enter the test caller directory.');
    }
    $this->callerDirectory = $resolvedCallerDirectory;
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (isset($this->originalWorkingDirectory)) {
      chdir($this->originalWorkingDirectory);
    }
    if (isset($this->temporaryDirectory) && $this->filesystem->exists($this->temporaryDirectory)) {
      $this->filesystem->remove($this->temporaryDirectory);
    }

    parent::tearDown();
  }

  /**
   * Tests real generation parity and working-directory restoration.
   */
  public function testGenerateMatchesDrupalCoreAndRestoresWorkingDirectory(): void {
    $this->writeStarterRecipe();
    $request = $this->createRequest();

    $this->generateWithCore($request, 'themes/core-generated');
    $result = (new DrupalStarterkitThemeGenerator($this->appRoot))->generate($request);

    self::assertSame($this->callerDirectory, getcwd());
    self::assertSame(0, $result->exitCode);
    self::assertSame('themes/custom/happy_theme', $result->destinationPath);
    self::assertStringContainsString(
      'Theme generated successfully to themes/custom/happy_theme',
      implode("\n", $result->messages),
    );
    self::assertSame(
      $this->readDirectory($this->appRoot . '/themes/core-generated/happy_theme'),
      $this->readDirectory($this->appRoot . '/themes/custom/happy_theme'),
    );
  }

  /**
   * Tests working-directory restoration after a real nonzero exit.
   */
  public function testGenerateRestoresWorkingDirectoryAfterNonzeroExit(): void {
    $this->writeStarterRecipe();
    $this->filesystem->mkdir($this->appRoot . '/themes/custom/happy_theme');

    $result = (new DrupalStarterkitThemeGenerator($this->appRoot))->generate($this->createRequest());

    self::assertSame($this->callerDirectory, getcwd());
    self::assertSame(1, $result->exitCode);
    $message = implode("\n", $result->messages);
    self::assertStringContainsString('Theme could not be generated because the destination directory', $message);
    self::assertStringContainsString('themes/custom/happy_theme exists already.', $message);
  }

  /**
   * Tests working-directory restoration after a real core exception.
   */
  public function testGenerateRestoresWorkingDirectoryAfterException(): void {
    $this->writeStarterRecipe();
    $this->filesystem->dumpFile(
      $this->appRoot . '/themes/contrib/emulsify/whisk/whisk.info.yml',
      "type: theme\ncore_version_requirement: '^11.3 || ^12'\n",
    );

    $result = (new DrupalStarterkitThemeGenerator($this->appRoot))->generate($this->createRequest());

    self::assertSame($this->callerDirectory, getcwd());
    self::assertSame(1, $result->exitCode);
    self::assertStringContainsString(
      'Missing required keys (name)',
      implode("\n", $result->messages),
    );
  }

  /**
   * Creates the generation request used by the integration scenarios.
   */
  private function createRequest(): ThemeGenerationRequest {
    return new ThemeGenerationRequest(
      machineName: 'happy_theme',
      displayName: 'Happy Theme',
      description: 'Project theme: punctuation & metadata.',
      starterkitMachineName: 'whisk',
      destinationPath: 'themes/custom',
    );
  }

  /**
   * Writes a minimal Whisk starter recipe.
   */
  private function writeStarterRecipe(): void {
    $directory = $this->appRoot . '/themes/contrib/emulsify/whisk';
    $this->filesystem->mkdir($directory);
    $this->filesystem->dumpFile($directory . '/whisk.info.yml', <<<YAML
name: Whisk Starter
type: theme
base theme: false
core_version_requirement: '^11.3 || ^12'
version: 1.0.0
YAML . "\n");
    $this->filesystem->dumpFile($directory . '/whisk.starterkit.yml', "info: {}\n");
    $this->filesystem->dumpFile($directory . '/README.md', "Whisk Starter (whisk)\n");
  }

  /**
   * Generates a comparison theme through Drupal core directly.
   */
  private function generateWithCore(ThemeGenerationRequest $request, string $destinationPath): void {
    $input = new ArrayInput([
      'machine-name' => $request->machineName,
      '--name' => $request->displayName,
      '--description' => $request->description,
      '--starterkit' => $request->starterkitMachineName,
      '--path' => $destinationPath,
    ]);
    $input->setInteractive(FALSE);
    $output = new BufferedOutput();
    $workingDirectory = getcwd();

    try {
      $exitCode = (new GenerateTheme(NULL, $this->appRoot))->run($input, $output);
      self::assertSame(0, $exitCode, $output->fetch());
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
   * Reads a generated file.
   */
  private function readFile(string $path): string {
    $contents = file_get_contents($path);
    if ($contents === FALSE) {
      throw new \RuntimeException(sprintf('Failed to read generated file "%s".', $path));
    }

    return $contents;
  }

}
