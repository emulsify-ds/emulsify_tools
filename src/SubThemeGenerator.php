<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Generates Emulsify child themes.
 */
final class SubThemeGenerator {

  /**
   * Creates the generator service.
   *
   * @param \Symfony\Component\Filesystem\Filesystem $filesystem
   *   The Symfony filesystem helper.
   */
  public function __construct(
    private readonly Filesystem $filesystem,
  ) {}

  /**
   * Generates a customized child theme in place.
   *
   * @param string $directory
   *   The destination directory.
   * @param string $machineName
   *   The new machine name.
   * @param string $name
   *   The new human-readable name.
   */
  public function generate(string $directory, string $machineName, string $name): void {
    $originalMachineName = $this->discoverOriginalMachineName($directory);
    $this->removeStarterkitOnlyFiles($directory, $originalMachineName);

    foreach ($this->getFilesToMakeReplacements($directory) as $fileName) {
      $this->modifyFileContent($fileName, $this->getFileContentReplacementPairs($machineName, $name));
    }

    if ($originalMachineName !== $machineName) {
      // Rename directories first so any file paths that include the old theme
      // machine name still point at existing parent directories when files are
      // renamed afterward.
      $this->renameDirectories($directory, $originalMachineName, $machineName);
      $this->renameFiles($directory, $originalMachineName, $machineName);
    }
  }

  /**
   * Removes starterkit-only source metadata from the generated theme copy.
   */
  private function removeStarterkitOnlyFiles(string $directory, string $originalMachineName): void {
    $starterkitOnlyFiles = [
      $directory . '/project.emulsify.json',
      $directory . '/' . $originalMachineName . '.info.emulsify.yml',
      $directory . '/' . $originalMachineName . '.starterkit.yml',
    ];

    foreach ($starterkitOnlyFiles as $fileName) {
      if ($this->filesystem->exists($fileName)) {
        $this->filesystem->remove($fileName);
      }
    }
  }

  /**
   * Finds the source theme's machine name from its info file.
   *
   * @param string $directory
   *   The theme directory.
   *
   * @return string
   *   The original machine name.
   */
  private function discoverOriginalMachineName(string $directory): string {
    $finder = new Finder()
      ->files()
      ->depth('== 0')
      ->in($directory)
      ->name('*.info.emulsify.yml');

    foreach ($finder as $fileInfo) {
      return basename($fileInfo->getFilename(), '.info.emulsify.yml');
    }

    throw new \RuntimeException(sprintf('No *.info.emulsify.yml file was found in "%s".', $directory));
  }

  /**
   * Rename files.
   *
   * @param string $directory
   *   The theme directory.
   * @param string $originalMachineName
   *   The original machine name.
   * @param string $newMachineName
   *   The replacement machine name.
   */
  private function renameFiles(string $directory, string $originalMachineName, string $newMachineName): void {
    foreach ($this->getFileNamesToRename($directory, $originalMachineName) as $fileName) {
      $newFileName = dirname($fileName) . '/' . str_replace($originalMachineName, $newMachineName, basename($fileName));
      if (str_contains($newFileName, '.emulsify.')) {
        $newFileName = str_replace('.emulsify.', '.', $newFileName);
      }
      $this->filesystem->rename($fileName, $newFileName);
    }
  }

  /**
   * Rename directories.
   *
   * @param string $directory
   *   The theme directory.
   * @param string $originalMachineName
   *   The original machine name.
   * @param string $newMachineName
   *   The replacement machine name.
   */
  private function renameDirectories(string $directory, string $originalMachineName, string $newMachineName): void {
    foreach ($this->getDirectoryNamesToRename($directory, $originalMachineName) as $directoryName) {
      $newDirectoryName = dirname($directoryName) . '/' . str_replace($originalMachineName, $newMachineName, basename($directoryName));
      $this->filesystem->rename($directoryName, $newDirectoryName);
    }
  }

  /**
   * Modify file contents.
   *
   * @param string $fileName
   *   The file name to update.
   * @param array<string, string> $replacementPairs
   *   Replacement pairs keyed by source string.
   */
  private function modifyFileContent(string $fileName, array $replacementPairs): void {
    if (!$this->filesystem->exists($fileName)) {
      return;
    }

    $this->filesystem->dumpFile(
      $fileName,
      strtr($this->fileGetContents($fileName), $replacementPairs),
    );
  }

  /**
   * Get file names to rename.
   *
   * @param string $directory
   *   The theme directory.
   * @param string $originalMachineName
   *   The original machine name.
   *
   * @return string[]
   *   An array of file names.
   */
  private function getFileNamesToRename(string $directory, string $originalMachineName): array {
    $fileNames = [];
    $finder = new Finder()
      ->files()
      ->in($directory)
      ->name("*{$originalMachineName}*");

    foreach ($finder as $fileInfo) {
      $fileNames[] = $fileInfo->getPathname();
    }

    return $fileNames;
  }

  /**
   * Get directory names to rename.
   *
   * @param string $directory
   *   The theme directory.
   * @param string $originalMachineName
   *   The original machine name.
   *
   * @return string[]
   *   An array of directory names ordered deepest-first.
   */
  private function getDirectoryNamesToRename(string $directory, string $originalMachineName): array {
    $directoryNames = [];
    $finder = new Finder()
      ->directories()
      ->in($directory)
      ->name("*{$originalMachineName}*");

    foreach ($finder as $fileInfo) {
      $directoryNames[] = $fileInfo->getPathname();
    }

    usort(
      $directoryNames,
      static fn (string $left, string $right): int => strlen($right) <=> strlen($left),
    );

    return $directoryNames;
  }

  /**
   * Get file content replacement pairs.
   *
   * @param string $machineName
   *   The new machine name.
   * @param string $name
   *   The new human-readable name.
   *
   * @return string[]
   *   The replacement pairs.
   */
  private function getFileContentReplacementPairs(string $machineName, string $name): array {
    return [
      'EMULSIFY_NAME' => $name,
      'whisk' => $machineName,
    ];
  }

  /**
   * Get files to make replacements.
   *
   * @param string $directory
   *   The theme directory.
   *
   * @return string[]
   *   An array of files to make replacements on.
   */
  private function getFilesToMakeReplacements(string $directory): array {
    $fileNames = [];
    $finder = new Finder()
      ->files()
      ->in($directory);

    foreach ($finder as $fileInfo) {
      $fileNames[] = $fileInfo->getPathname();
    }

    return $fileNames;
  }

  /**
   * Get file contents.
   *
   * @return string
   *   The file contents.
   */
  private function fileGetContents(string $fileName): string {
    $content = file_get_contents($fileName);
    if ($content === FALSE) {
      throw new \RuntimeException(sprintf("Could not read file '%s'.", $fileName));
    }

    return $content;
  }

}
