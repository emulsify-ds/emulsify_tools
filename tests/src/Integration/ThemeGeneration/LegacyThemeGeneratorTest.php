<?php

declare(strict_types=1);

namespace Drupal\Tests\emulsify_tools\Integration\ThemeGeneration;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\emulsify_tools\Archive\StarterRecipeArchiveExtractor;
use Drupal\emulsify_tools\SubThemeGenerator;
use Drupal\emulsify_tools\ThemeGeneration\LegacyThemeGenerator;
use Drupal\emulsify_tools\ThemeGeneration\ThemeGenerationRequest;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Tests the deprecated Emulsify Drupal 6.x compatibility generator.
 */
#[CoversClass(LegacyThemeGenerator::class)]
#[Group('emulsify_tools')]
final class LegacyThemeGeneratorTest extends UnitTestCase {

  /**
   * Expected user-facing deprecation warning.
   */
  private const DEPRECATION_WARNING = 'The legacy Emulsify Drupal 6.x generation path is deprecated. It will be removed in Emulsify Tools 3.0.0. Projects should migrate to Emulsify Drupal 7.x and Drupal Starterkit generation.';

  /**
   * Filesystem helper.
   */
  private Filesystem $filesystem;

  /**
   * Temporary Drupal application root.
   */
  private string $appRoot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->filesystem = new Filesystem();
    $this->appRoot = sys_get_temp_dir() . '/emulsify_tools_legacy_' . bin2hex(random_bytes(8));
    $sourceFixture = dirname(__DIR__, 3) . '/fixtures/ThemeGeneration/emulsify-6/whisk';
    if (!is_dir($sourceFixture)) {
      throw new \RuntimeException(sprintf('Legacy Whisk fixture not found at "%s".', $sourceFixture));
    }

    $this->filesystem->mkdir($this->appRoot);
    $this->filesystem->mirror(
      $sourceFixture,
      $this->appRoot . '/themes/contrib/emulsify/whisk',
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (isset($this->appRoot) && $this->filesystem->exists($this->appRoot)) {
      $this->filesystem->remove($this->appRoot);
    }

    parent::tearDown();
  }

  /**
   * Tests a real legacy fixture is generated with requested display metadata.
   */
  public function testGenerateLegacyTheme(): void {
    $request = new ThemeGenerationRequest(
      machineName: 'legacy_theme',
      displayName: "Legacy & Partners' Theme",
      description: "Project theme: Callin's metadata & components #1.",
      starterkitMachineName: 'whisk',
      destinationPath: 'themes/custom',
    );

    $result = $this->createGenerator()->generate($request);

    self::assertSame(0, $result->exitCode);
    self::assertSame([], $result->messages);
    self::assertSame('themes/custom/legacy_theme', $result->destinationPath);
    self::assertSame([self::DEPRECATION_WARNING], $result->warnings);

    $destination = $this->appRoot . '/themes/custom/legacy_theme';
    self::assertDirectoryExists($destination);
    self::assertFileDoesNotExist($destination . '/whisk.info.emulsify.yml');
    self::assertFileDoesNotExist($destination . '/project.emulsify.json');

    $info = $this->readYaml($destination . '/legacy_theme.info.yml');
    self::assertSame("Legacy & Partners' Theme", $info['name']);
    self::assertSame("Project theme: Callin's metadata & components #1.", $info['description']);
    self::assertSame([
      'drupal:components (^3.0)',
      'drupal:emulsify_tools (^2.0)',
    ], $info['dependencies']);
  }

  /**
   * Tests an existing destination is rejected without modifying its contents.
   */
  public function testGenerateProtectsExistingDestination(): void {
    $destination = $this->appRoot . '/themes/custom/protected_theme';
    $this->filesystem->mkdir($destination);
    $this->filesystem->dumpFile($destination . '/sentinel.txt', "do not replace\n");
    $entriesBefore = $this->readDirectoryEntries($destination);

    $result = $this->createGenerator()->generate(new ThemeGenerationRequest(
      machineName: 'protected_theme',
      displayName: 'Protected Theme',
      description: 'Must not be generated.',
      starterkitMachineName: 'whisk',
      destinationPath: 'themes/custom',
    ));

    self::assertSame(1, $result->exitCode);
    self::assertNull($result->destinationPath);
    self::assertSame(
      ['The destination theme already exists: themes/custom/protected_theme'],
      $result->messages,
    );
    self::assertSame([self::DEPRECATION_WARNING], $result->warnings);
    self::assertSame($entriesBefore, $this->readDirectoryEntries($destination));
    self::assertSame("do not replace\n", $this->readFile($destination . '/sentinel.txt'));
  }

  /**
   * Creates the legacy generator under test.
   */
  private function createGenerator(): LegacyThemeGenerator {
    $themeExtensionList = $this->createMock(ThemeExtensionList::class);
    $themeExtensionList->method('exists')
      ->with('emulsify')
      ->willReturn(TRUE);
    $themeExtensionList->method('getPath')
      ->with('emulsify')
      ->willReturn('themes/contrib/emulsify');

    return new LegacyThemeGenerator(
      $themeExtensionList,
      new StarterRecipeArchiveExtractor($this->filesystem),
      new SubThemeGenerator($this->filesystem),
      $this->filesystem,
      $this->appRoot,
    );
  }

  /**
   * Reads and decodes a YAML mapping.
   *
   * @return array<string, mixed>
   *   The decoded mapping.
   */
  private function readYaml(string $path): array {
    $decoded = Yaml::decode($this->readFile($path));
    if (!is_array($decoded)) {
      throw new \RuntimeException(sprintf('Expected "%s" to contain a YAML mapping.', $path));
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
   * Reads the direct entries in a directory.
   *
   * @return list<string>
   *   Sorted direct entries, excluding dot entries.
   */
  private function readDirectoryEntries(string $path): array {
    $entries = scandir($path);
    if ($entries === FALSE) {
      throw new \RuntimeException(sprintf('Unable to read directory "%s".', $path));
    }

    return array_values(array_diff($entries, ['.', '..']));
  }

}
