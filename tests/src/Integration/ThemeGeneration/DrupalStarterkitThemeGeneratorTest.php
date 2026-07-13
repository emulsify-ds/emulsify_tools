<?php

declare(strict_types=1);

namespace Drupal\Tests\emulsify_tools\Integration\ThemeGeneration;

use Drupal\Component\Serialization\Yaml;
use Drupal\emulsify_tools\ThemeGeneration\DrupalStarterkitThemeGenerator;
use Drupal\emulsify_tools\ThemeGeneration\ThemeGenerationRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Tests Drupal Starterkit integration through the application adapter.
 */
#[CoversClass(DrupalStarterkitThemeGenerator::class)]
#[Group('emulsify_tools')]
final class DrupalStarterkitThemeGeneratorTest extends TestCase {

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
   * Tests one real core generation through the adapter.
   */
  public function testGenerateCreatesExpectedThemeAndRestoresWorkingDirectory(): void {
    $this->writeStarterRecipe();

    $result = $this->generator()->generate($this->createRequest());

    self::assertSame($this->callerDirectory, getcwd());
    self::assertSame(0, $result->exitCode);
    self::assertSame('themes/custom/happy_theme', $result->destinationPath);
    self::assertStringContainsString(
      'Theme generated successfully to themes/custom/happy_theme',
      implode("\n", $result->messages),
    );

    $destination = $this->appRoot . '/themes/custom/happy_theme';
    self::assertDirectoryExists($destination);
    self::assertFileExists($destination . '/happy_theme.info.yml');
    self::assertFileDoesNotExist($destination . '/whisk.info.yml');
    self::assertFileDoesNotExist($destination . '/whisk.info.emulsify.yml');
    self::assertFileDoesNotExist($destination . '/whisk.starterkit.yml');

    $info = $this->readYaml($destination . '/happy_theme.info.yml');
    self::assertSame('Happy Theme', $info['name']);
    self::assertSame('Project theme: punctuation & metadata.', $info['description']);
    self::assertSame('emulsify', $info['base theme']);
    self::assertSame('^11.3 || ^12', $info['core_version_requirement']);
    self::assertSame('1.0.0', $info['version']);
    self::assertSame('whisk:1.0.0', $info['generator']);
    self::assertSame(['drupal:emulsify_tools (^2.0)'], $info['dependencies']);
    self::assertSame(['happy_theme/global'], $info['libraries']);
    self::assertArrayNotHasKey('hidden', $info);

    self::assertFileExists($destination . '/config/install/happy_theme.settings.yml');
    self::assertFileDoesNotExist($destination . '/config/install/whisk.settings.yml');
    self::assertSame([
      'favicon_source_filename' => 'happy_theme.svg',
    ], $this->readYaml($destination . '/config/install/happy_theme.settings.yml'));

    self::assertFileExists($destination . '/config/schema/happy_theme.schema.yml');
    self::assertFileDoesNotExist($destination . '/config/schema/whisk.schema.yml');
    self::assertSame([
      'happy_theme.settings' => [
        'type' => 'config_object',
        'label' => 'happy_theme settings',
      ],
    ], $this->readYaml($destination . '/config/schema/happy_theme.schema.yml'));

    self::assertSame([
      'project' => [
        'platform' => 'drupal',
        'name' => 'happy_theme',
        'machineName' => 'happy_theme',
        'singleDirectoryComponents' => TRUE,
      ],
      'starter' => [
        'repository' => 'https://github.com/emulsify-ds/emulsify-drupal.git',
      ],
    ], $this->readJson($destination . '/project.emulsify.json'));
    self::assertSame(
      "happy_theme starter fixture.\n",
      $this->readFile($destination . '/README.md'),
    );
  }

  /**
   * Tests an existing destination is preserved after a nonzero core exit.
   */
  public function testGeneratePreservesExistingDestinationAndRestoresWorkingDirectory(): void {
    $this->writeStarterRecipe();
    $destination = $this->appRoot . '/themes/custom/happy_theme';
    $this->filesystem->mkdir($destination);
    $this->filesystem->dumpFile($destination . '/sentinel.txt', "do not replace\n");

    $result = $this->generator()->generate($this->createRequest());

    self::assertSame($this->callerDirectory, getcwd());
    self::assertSame(1, $result->exitCode);
    self::assertNull($result->destinationPath);
    $message = implode("\n", $result->messages);
    self::assertStringContainsString('Theme could not be generated because the destination directory', $message);
    self::assertStringContainsString('themes/custom/happy_theme exists already.', $message);
    self::assertSame(['sentinel.txt'], $this->readDirectoryEntries($destination));
    self::assertSame("do not replace\n", $this->readFile($destination . '/sentinel.txt'));
  }

  /**
   * Tests core exceptions become results and restore the working directory.
   */
  public function testGenerateTranslatesExceptionAndRestoresWorkingDirectory(): void {
    $this->writeStarterRecipe();
    $this->filesystem->dumpFile(
      $this->appRoot . '/themes/contrib/emulsify/whisk/whisk.info.yml',
      "type: theme\ncore_version_requirement: '^11.3 || ^12'\n",
    );

    $result = $this->generator()->generate($this->createRequest());

    self::assertSame($this->callerDirectory, getcwd());
    self::assertSame(1, $result->exitCode);
    self::assertNull($result->destinationPath);
    self::assertStringContainsString('Missing required keys (name)', implode("\n", $result->messages));
    self::assertDirectoryDoesNotExist($this->appRoot . '/themes/custom/happy_theme');
  }

  /**
   * Tests missing Whisk source behavior through Drupal core.
   */
  public function testGenerateReportsMissingWhiskSource(): void {
    $result = $this->generator()->generate($this->createRequest());

    self::assertSame($this->callerDirectory, getcwd());
    self::assertSame(1, $result->exitCode);
    self::assertNull($result->destinationPath);
    self::assertStringContainsString(
      'Theme source theme whisk cannot be found.',
      implode("\n", $result->messages),
    );
    self::assertDirectoryDoesNotExist($this->appRoot . '/themes/custom/happy_theme');
  }

  /**
   * Creates the adapter under test.
   */
  private function generator(): DrupalStarterkitThemeGenerator {
    return new DrupalStarterkitThemeGenerator($this->appRoot);
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
   * Copies the checked-in Emulsify 7 Whisk fixture into the app root.
   */
  private function writeStarterRecipe(): void {
    $source = dirname(__DIR__, 3) . '/fixtures/ThemeGeneration/emulsify-7/whisk';
    if (!is_dir($source)) {
      throw new \RuntimeException(sprintf('Whisk fixture not found at "%s".', $source));
    }

    $this->filesystem->mirror(
      $source,
      $this->appRoot . '/themes/contrib/emulsify/whisk',
    );
  }

  /**
   * Reads and decodes a YAML mapping.
   *
   * @return array<string, mixed>
   *   Decoded mapping.
   */
  private function readYaml(string $path): array {
    $decoded = Yaml::decode($this->readFile($path));
    if (!is_array($decoded)) {
      throw new \RuntimeException(sprintf('Expected "%s" to contain a YAML mapping.', $path));
    }

    return $decoded;
  }

  /**
   * Reads and decodes a JSON object.
   *
   * @return array<string, mixed>
   *   Decoded object.
   */
  private function readJson(string $path): array {
    $decoded = json_decode($this->readFile($path), TRUE, flags: JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
      throw new \RuntimeException(sprintf('Expected "%s" to contain a JSON object.', $path));
    }

    return $decoded;
  }

  /**
   * Reads a file or fails with a useful fixture error.
   */
  private function readFile(string $path): string {
    $contents = file_get_contents($path);
    if ($contents === FALSE) {
      throw new \RuntimeException(sprintf('Unable to read "%s".', $path));
    }

    return $contents;
  }

  /**
   * Reads sorted direct directory entries.
   *
   * @return list<string>
   *   Direct entries excluding dot entries.
   */
  private function readDirectoryEntries(string $path): array {
    $entries = scandir($path);
    if ($entries === FALSE) {
      throw new \RuntimeException(sprintf('Unable to read directory "%s".', $path));
    }

    $entries = array_values(array_diff($entries, ['.', '..']));
    sort($entries);
    return $entries;
  }

}
