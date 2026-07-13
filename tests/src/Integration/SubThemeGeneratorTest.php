<?php

declare(strict_types=1);

namespace Drupal\Tests\emulsify_tools\Integration;

use Drupal\Component\Serialization\Yaml;
use Drupal\emulsify_tools\SubThemeGenerator;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Tests child theme generation.
 */
#[CoversClass(SubThemeGenerator::class)]
#[Group('emulsify_tools')]
final class SubThemeGeneratorTest extends UnitTestCase {

  /**
   * The filesystem helper used by the generator.
   */
  private Filesystem $filesystem;

  /**
   * A temporary directory for fixture files.
   */
  private string $temporaryDirectory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->filesystem = new Filesystem();
    $this->temporaryDirectory = sys_get_temp_dir() . '/emulsify_tools_' . bin2hex(random_bytes(8));
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
   * Tests file, directory, and content replacements during generation.
   */
  public function testGenerateCustomizesLegacyWhiskFixture(): void {
    $fixtureDirectory = dirname(__DIR__, 2) . '/fixtures/ThemeGeneration/emulsify-6/whisk';
    $themeDirectory = $this->temporaryDirectory . '/theme';
    $displayName = "Child's Theme: News & Events";
    $description = "Built for editors: fast, focused & accessible.";

    self::assertFileDoesNotExist($fixtureDirectory . '/whisk.info.yml');
    self::assertFileDoesNotExist($fixtureDirectory . '/whisk.starterkit.yml');
    $this->filesystem->mirror($fixtureDirectory, $themeDirectory);

    $generator = new SubThemeGenerator($this->filesystem);
    $generator->generate($themeDirectory, 'child', $displayName, $description);

    self::assertFileDoesNotExist($themeDirectory . '/whisk.info.emulsify.yml');
    self::assertFileDoesNotExist($themeDirectory . '/project.emulsify.json');
    self::assertFileExists($themeDirectory . '/child.info.yml');

    $info = Yaml::decode($this->readFile($themeDirectory . '/child.info.yml'));
    self::assertIsArray($info);
    self::assertSame($displayName, $info['name']);
    self::assertSame($description, $info['description']);
    self::assertSame([
      'drupal:components (^3.0)',
      'drupal:emulsify_tools (^2.0)',
    ], $info['dependencies']);

    self::assertFileExists($themeDirectory . '/config/install/child.settings.yml');
    self::assertFileExists($themeDirectory . '/config/schema/child.schema.yml');
    self::assertDirectoryDoesNotExist($themeDirectory . '/components/whisk-section');
    self::assertDirectoryExists($themeDirectory . '/components/child-section');
    self::assertFileDoesNotExist($themeDirectory . '/components/child-section/whisk-card.twig');
    self::assertFileExists($themeDirectory . '/components/child-section/child-card.twig');
    self::assertSame(
      "Theme machine: child\n",
      $this->readFile($themeDirectory . '/components/child-section/child-card.twig'),
    );
  }

  /**
   * Tests an empty description preserves legacy metadata and normalizes info.
   */
  public function testGeneratePreservesLegacyDescriptionForWhiskMachineName(): void {
    $fixtureDirectory = dirname(__DIR__, 2) . '/fixtures/ThemeGeneration/emulsify-6/whisk';
    $themeDirectory = $this->temporaryDirectory . '/whisk-theme';
    $this->filesystem->mirror($fixtureDirectory, $themeDirectory);

    $generator = new SubThemeGenerator($this->filesystem);
    $generator->generate($themeDirectory, 'whisk', 'Whisk Project', '');

    self::assertFileDoesNotExist($themeDirectory . '/whisk.info.emulsify.yml');
    self::assertFileExists($themeDirectory . '/whisk.info.yml');
    $info = Yaml::decode($this->readFile($themeDirectory . '/whisk.info.yml'));
    self::assertIsArray($info);
    self::assertSame('Whisk Project', $info['name']);
    self::assertSame('A subtheme build upon the Emulsify Design System.', $info['description']);
  }

  /**
   * Tests direct callers retain modern starter-only metadata cleanup.
   */
  public function testGenerateRemovesModernStarterkitMetadata(): void {
    $themeDirectory = $this->temporaryDirectory . '/modern-source';
    $this->filesystem->mkdir($themeDirectory);
    $this->filesystem->dumpFile($themeDirectory . '/whisk.info.yml', "name: EMULSIFY_NAME\ntype: theme\n");
    $this->filesystem->dumpFile($themeDirectory . '/whisk.info.emulsify.yml', "name: whisk\ntype: theme\n");
    $this->filesystem->dumpFile($themeDirectory . '/whisk.starterkit.yml', "info: {}\n");
    $this->filesystem->dumpFile($themeDirectory . '/project.emulsify.json', "{}\n");

    $generator = new SubThemeGenerator($this->filesystem);
    $generator->generate($themeDirectory, 'child', 'Child Theme');

    self::assertFileDoesNotExist($themeDirectory . '/whisk.info.emulsify.yml');
    self::assertFileDoesNotExist($themeDirectory . '/whisk.starterkit.yml');
    self::assertFileDoesNotExist($themeDirectory . '/project.emulsify.json');
    self::assertFileExists($themeDirectory . '/child.info.yml');
    $info = Yaml::decode($this->readFile($themeDirectory . '/child.info.yml'));
    self::assertIsArray($info);
    self::assertSame('Child Theme', $info['name']);
  }

  /**
   * Tests Emulsify Drupal 6.0 and 6.1 dependency metadata is updated.
   */
  public function testGenerateUpdatesEarlyLegacyToolsDependency(): void {
    $fixtureDirectory = dirname(__DIR__, 2) . '/fixtures/ThemeGeneration/emulsify-6/whisk';
    $themeDirectory = $this->temporaryDirectory . '/early-legacy';
    $this->filesystem->mirror($fixtureDirectory, $themeDirectory);
    $infoPath = $themeDirectory . '/whisk.info.emulsify.yml';
    $this->filesystem->dumpFile(
      $infoPath,
      str_replace('(^1.0)', '(^4.0)', $this->readFile($infoPath)),
    );

    $generator = new SubThemeGenerator($this->filesystem);
    $generator->generate($themeDirectory, 'early_child', 'Early Child');

    $info = Yaml::decode($this->readFile($themeDirectory . '/early_child.info.yml'));
    self::assertIsArray($info);
    self::assertSame([
      'drupal:components (^3.0)',
      'drupal:emulsify_tools (^2.0)',
    ], $info['dependencies']);
  }

  /**
   * Tests that the source theme info file is required.
   */
  public function testGenerateRequiresAnEmulsifyInfoFile(): void {
    $themeDirectory = $this->temporaryDirectory . '/missing-info';
    $this->filesystem->mkdir($themeDirectory);

    $generator = new SubThemeGenerator($this->filesystem);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage(sprintf('No *.info.emulsify.yml file was found in "%s".', $themeDirectory));
    $generator->generate($themeDirectory, 'child', 'Child Theme', 'Description');
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
