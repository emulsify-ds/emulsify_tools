<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools\ThemeGeneration;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Extension\Exception\UnknownExtensionException;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\emulsify_tools\Archive\StarterRecipeArchiveExtractor;
use Drupal\emulsify_tools\SubThemeGenerator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Generates themes from the Emulsify Drupal 6.x Whisk source format.
 *
 * @deprecated in emulsify_tools:2.2.0 and is removed from
 *   emulsify_tools:3.0.0. Use
 *   \Drupal\emulsify_tools\ThemeGeneration\ThemeGeneratorInterface instead.
 * @see https://www.drupal.org/project/drupal/issues/3364885
 */
final class LegacyThemeGenerator implements ThemeGeneratorInterface {

  /**
   * User-facing legacy-path deprecation warning.
   */
  private const DEPRECATION_WARNING = 'The legacy Emulsify Drupal 6.x generation path is deprecated. It will be removed in Emulsify Tools 3.0.0. Projects should migrate to Emulsify Drupal 7.x and Drupal Starterkit generation.';

  /**
   * Creates a legacy theme generator.
   */
  public function __construct(
    private readonly ThemeExtensionList $themeExtensionList,
    private readonly StarterRecipeArchiveExtractor $starterRecipeArchiveExtractor,
    private readonly SubThemeGenerator $subThemeGenerator,
    private readonly Filesystem $filesystem,
    private readonly string $appRoot,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function generate(ThemeGenerationRequest $request): ThemeGenerationResult {
    $temporaryDirectory = NULL;
    $warnings = [self::DEPRECATION_WARNING];

    try {
      $sourceDirectory = $this->getSourceDirectory($request->starterkitMachineName);
      if (UrlHelper::isValid($sourceDirectory, TRUE)) {
        $temporaryDirectory = $this->createTemporaryDirectory();
        $sourceDirectory = $this->extractRemoteSource($sourceDirectory, $temporaryDirectory);
      }
      else {
        $relativeSourceDirectory = $sourceDirectory;
        $sourceDirectory = rtrim($this->appRoot, '/') . '/' . ltrim($sourceDirectory, '/');
        if (!is_dir($sourceDirectory)) {
          throw new \RuntimeException(sprintf(
            'The Emulsify Whisk source directory was not found: %s',
            $relativeSourceDirectory,
          ));
        }
      }

      $destinationPath = trim($request->destinationPath, '/') . '/' . $request->machineName;
      $destinationDirectory = rtrim($this->appRoot, '/') . '/' . $destinationPath;
      if ($this->filesystem->exists($destinationDirectory)) {
        return new ThemeGenerationResult(
          1,
          [sprintf('The destination theme already exists: %s', $destinationPath)],
          warnings: $warnings,
        );
      }

      $this->filesystem->mirror($sourceDirectory, $destinationDirectory);
      $this->subThemeGenerator->generate(
        $destinationDirectory,
        $request->machineName,
        $request->displayName,
        $request->description,
      );

      return new ThemeGenerationResult(0, [], $destinationPath, $warnings);
    }
    catch (\Throwable $exception) {
      return new ThemeGenerationResult(1, [$exception->getMessage()], warnings: $warnings);
    }
    finally {
      if ($temporaryDirectory !== NULL && $this->filesystem->exists($temporaryDirectory)) {
        $this->filesystem->remove($temporaryDirectory);
      }
    }
  }

  /**
   * Resolves the Emulsify Whisk source path.
   */
  private function getSourceDirectory(string $starterkitMachineName): string {
    try {
      $emulsifyPath = $this->themeExtensionList->getPath('emulsify');
    }
    catch (UnknownExtensionException $exception) {
      throw new \RuntimeException('The Emulsify parent theme was not found: emulsify', 0, $exception);
    }

    return rtrim($emulsifyPath, '/') . '/' . $starterkitMachineName;
  }

  /**
   * Downloads and extracts a remote legacy source archive.
   */
  private function extractRemoteSource(string $source, string $temporaryDirectory): string {
    $path = parse_url($source, PHP_URL_PATH);
    $fileName = pathinfo(is_string($path) ? $path : '', PATHINFO_BASENAME);
    $archivePath = "{$temporaryDirectory}/pack/{$fileName}";
    $recipeDirectory = "{$temporaryDirectory}/recipe";

    $this->filesystem->mkdir(dirname($archivePath));
    $this->filesystem->copy($source, $archivePath);
    $this->starterRecipeArchiveExtractor->extract($archivePath, $recipeDirectory);

    $directDescendants = (new Finder())->in($recipeDirectory)->depth('== 0');
    if ($directDescendants->count() !== 1) {
      return $recipeDirectory;
    }

    $iterator = $directDescendants->getIterator();
    $iterator->rewind();
    $firstEntry = $iterator->current();

    return $firstEntry->isDir() ? $firstEntry->getPathname() : $recipeDirectory;
  }

  /**
   * Creates a temporary directory for remote legacy sources.
   */
  private function createTemporaryDirectory(): string {
    $temporaryDirectory = sys_get_temp_dir() . '/emulsify-tools-' . bin2hex(random_bytes(8));
    $this->filesystem->mkdir($temporaryDirectory);

    return $temporaryDirectory;
  }

}
