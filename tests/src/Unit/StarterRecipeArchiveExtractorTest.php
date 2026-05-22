<?php

declare(strict_types=1);

namespace Drupal\Tests\emulsify_tools\Unit;

use Drupal\Core\Archiver\ArchiveTar;
use Drupal\emulsify_tools\Archive\StarterRecipeArchiveExtractor;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Tests starter recipe archive extraction.
 */
#[CoversClass(StarterRecipeArchiveExtractor::class)]
#[Group('emulsify_tools')]
final class StarterRecipeArchiveExtractorTest extends UnitTestCase {

  /**
   * Filesystem helper for fixture setup.
   */
  private Filesystem $filesystem;

  /**
   * A temporary working directory.
   */
  private string $temporaryDirectory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->filesystem = new Filesystem();
    $this->temporaryDirectory = sys_get_temp_dir() . '/emulsify_tools_archive_' . bin2hex(random_bytes(8));
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
   * Tests zip archives can be extracted without Drupal's archiver manager.
   */
  public function testExtractsZipArchives(): void {
    if (!class_exists(\ZipArchive::class)) {
      self::markTestSkipped('The PHP zip extension is required for zip archive extraction tests.');
    }

    $archivePath = $this->temporaryDirectory . '/starter.zip';
    $destinationDirectory = $this->temporaryDirectory . '/zip-extracted';

    $archive = new \ZipArchive();
    self::assertTrue($archive->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
    $archive->addFromString('whisk/README.md', "zip fixture\n");
    $archive->close();

    $extractor = new StarterRecipeArchiveExtractor($this->filesystem);
    $extractor->extract($archivePath, $destinationDirectory);

    self::assertFileExists($destinationDirectory . '/whisk/README.md');
    self::assertSame("zip fixture\n", $this->readFile($destinationDirectory . '/whisk/README.md'));
  }

  /**
   * Tests tar.gz archives can be extracted with core's non-deprecated wrapper.
   */
  public function testExtractsTarGzArchives(): void {
    $sourceDirectory = $this->temporaryDirectory . '/tar-source';
    $archivePath = $this->temporaryDirectory . '/starter.tar.gz';
    $destinationDirectory = $this->temporaryDirectory . '/tar-extracted';

    $this->filesystem->mkdir($sourceDirectory . '/whisk');
    $this->writeFile($sourceDirectory . '/whisk/README.md', "tar fixture\n");

    $archive = new ArchiveTar($archivePath, 'gz');
    self::assertTrue($archive->createModify([$sourceDirectory . '/whisk'], '', $sourceDirectory));

    $extractor = new StarterRecipeArchiveExtractor($this->filesystem);
    $extractor->extract($archivePath, $destinationDirectory);

    self::assertFileExists($destinationDirectory . '/whisk/README.md');
    self::assertSame("tar fixture\n", $this->readFile($destinationDirectory . '/whisk/README.md'));
  }

  /**
   * Tests unsupported archive formats are rejected.
   */
  public function testRejectsUnsupportedArchiveFormats(): void {
    $archivePath = $this->temporaryDirectory . '/starter.rar';
    $this->writeFile($archivePath, 'fixture');

    $extractor = new StarterRecipeArchiveExtractor($this->filesystem);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage(sprintf('Unsupported starter recipe archive format: "%s".', strtolower($archivePath)));
    $extractor->extract($archivePath, $this->temporaryDirectory . '/unsupported');
  }

  /**
   * Writes a fixture file.
   */
  private function writeFile(string $path, string $contents): void {
    $result = file_put_contents($path, $contents);
    if ($result === FALSE) {
      throw new \RuntimeException(sprintf('Failed to write fixture file "%s".', $path));
    }
  }

  /**
   * Reads an extracted fixture file.
   */
  private function readFile(string $path): string {
    $contents = file_get_contents($path);
    if ($contents === FALSE) {
      throw new \RuntimeException(sprintf('Failed to read fixture file "%s".', $path));
    }

    return $contents;
  }

}
