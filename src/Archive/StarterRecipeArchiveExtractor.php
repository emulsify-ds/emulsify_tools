<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools\Archive;

use Drupal\Core\Archiver\ArchiveTar;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Extracts starter recipe archives without Drupal's deprecated archiver API.
 */
final class StarterRecipeArchiveExtractor {

  /**
   * Creates the extractor.
   */
  public function __construct(
    private readonly Filesystem $filesystem,
  ) {}

  /**
   * Extracts a supported starter recipe archive.
   */
  public function extract(string $archivePath, string $destinationDirectory): void {
    $format = $this->detectFormat($archivePath);
    $this->filesystem->mkdir($destinationDirectory);

    if ($format === 'zip') {
      $this->extractZip($archivePath, $destinationDirectory);
      return;
    }

    $this->extractTar($archivePath, $destinationDirectory, $format);
  }

  /**
   * Detects the supported archive format from the file name.
   *
   * @return 'bz2'|'gz'|'lzma2'|'none'|'zip'
   *   The detected archive format.
   */
  private function detectFormat(string $archivePath): string {
    $archivePath = strtolower($archivePath);

    return match (TRUE) {
      str_ends_with($archivePath, '.zip') => 'zip',
      str_ends_with($archivePath, '.tar.gz'),
      str_ends_with($archivePath, '.tgz') => 'gz',
      str_ends_with($archivePath, '.tar.bz2'),
      str_ends_with($archivePath, '.tbz'),
      str_ends_with($archivePath, '.tbz2') => 'bz2',
      str_ends_with($archivePath, '.tar.xz'),
      str_ends_with($archivePath, '.txz') => 'lzma2',
      str_ends_with($archivePath, '.tar') => 'none',
      default => throw new \RuntimeException(sprintf('Unsupported starter recipe archive format: "%s".', $archivePath)),
    };
  }

  /**
   * Extracts a zip archive with PHP's zip extension.
   */
  private function extractZip(string $archivePath, string $destinationDirectory): void {
    if (!class_exists(\ZipArchive::class)) {
      throw new \RuntimeException('Zip archive extraction requires the PHP zip extension.');
    }

    $archive = new \ZipArchive();
    $openResult = $archive->open($archivePath);
    if ($openResult !== TRUE) {
      throw new \RuntimeException(sprintf('Unable to open zip starter recipe archive "%s" (error code %s).', $archivePath, $openResult));
    }

    try {
      if (!$archive->extractTo($destinationDirectory)) {
        throw new \RuntimeException(sprintf('Unable to extract zip starter recipe archive "%s".', $archivePath));
      }
    }
    finally {
      $archive->close();
    }
  }

  /**
   * Extracts a tar-based archive.
   *
   * @param 'bz2'|'gz'|'lzma2'|'none' $compression
   *   The tar compression format.
   */
  private function extractTar(string $archivePath, string $destinationDirectory, string $compression): void {
    $archive = new ArchiveTar(
      $archivePath,
      $compression === 'none' ? NULL : $compression,
    );

    if (!$archive->extract($destinationDirectory)) {
      throw new \RuntimeException(sprintf('Unable to extract tar starter recipe archive "%s".', $archivePath));
    }
  }

}
