<?php

declare(strict_types=1);

namespace Drupal\Tests\emulsify_tools\Unit;

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
  public function testGenerateRenamesThemeAssetsAndRewritesContent(): void {
    $themeDirectory = $this->temporaryDirectory . '/theme';
    $this->filesystem->mkdir([
      $themeDirectory,
      $themeDirectory . '/components/whisk-parent/whisk-child',
    ]);

    $this->writeFile(
      $themeDirectory . '/whisk.info.yml',
      "name: EMULSIFY_NAME\n",
    );
    $this->writeFile(
      $themeDirectory . '/whisk.info.emulsify.yml',
      "hidden: false\n",
    );
    $this->writeFile(
      $themeDirectory . '/whisk.starterkit.yml',
      "ignore: []\n",
    );
    $this->writeFile(
      $themeDirectory . '/project.emulsify.json',
      "{\"project\":{\"machineName\":\"whisk\",\"platform\":\"drupal\"}}\n",
    );
    $this->writeFile(
      $themeDirectory . '/components/whisk-parent/whisk-child/whisk-template.twig',
      "machine: whisk\nlabel: EMULSIFY_NAME\n",
    );
    $this->writeFile(
      $themeDirectory . '/README.md',
      "Theme: EMULSIFY_NAME (whisk)\n",
    );

    $generator = new SubThemeGenerator($this->filesystem);
    $generator->generate($themeDirectory, 'new_theme', 'New Theme');

    self::assertFileDoesNotExist($themeDirectory . '/whisk.info.emulsify.yml');
    self::assertFileDoesNotExist($themeDirectory . '/whisk.starterkit.yml');
    self::assertFileExists($themeDirectory . '/project.emulsify.json');
    self::assertSame(
      "{\"project\":{\"machineName\":\"new_theme\",\"platform\":\"drupal\"}}\n",
      $this->readFile($themeDirectory . '/project.emulsify.json'),
    );
    self::assertFileExists($themeDirectory . '/new_theme.info.yml');
    self::assertSame("name: New Theme\n", $this->readFile($themeDirectory . '/new_theme.info.yml'));

    self::assertDirectoryDoesNotExist($themeDirectory . '/components/whisk-parent');
    self::assertDirectoryExists($themeDirectory . '/components/new_theme-parent/new_theme-child');
    self::assertFileExists($themeDirectory . '/components/new_theme-parent/new_theme-child/new_theme-template.twig');
    self::assertSame(
      "machine: new_theme\nlabel: New Theme\n",
      $this->readFile($themeDirectory . '/components/new_theme-parent/new_theme-child/new_theme-template.twig'),
    );

    self::assertSame("Theme: New Theme (new_theme)\n", $this->readFile($themeDirectory . '/README.md'));
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
    $generator->generate($themeDirectory, 'new_theme', 'New Theme');
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
