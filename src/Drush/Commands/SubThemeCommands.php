<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools\Drush\Commands;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Archiver\ArchiverManager;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\emulsify_tools\Favicon\ChildThemeFaviconConfigRepairer;
use Drupal\emulsify_tools\SubThemeGenerator;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Robo\Collection\CollectionBuilder;
use Robo\Contract\BuilderAwareInterface;
use Robo\State\Data as RoboStateData;
use Robo\Task\Archive\Tasks as ArchiveTaskLoader;
use Robo\Task\Filesystem\Tasks as FilesystemTaskLoader;
use Robo\TaskAccessor;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Provides Drush commands for Emulsify tools.
 */
final class SubThemeCommands extends DrushCommands implements BuilderAwareInterface {

  use AutowireTrait;
  use TaskAccessor;
  use ArchiveTaskLoader;
  use FilesystemTaskLoader;

  /**
   * Creates the command.
   */
  public function __construct(
    private readonly ThemeExtensionList $themeExtensionList,
    private readonly ArchiverManager $archiverManager,
    private readonly SubThemeGenerator $subThemeGenerator,
    private readonly Filesystem $filesystem,
    private readonly ChildThemeFaviconConfigRepairer $childThemeFaviconConfigRepairer,
  ) {
    parent::__construct();
  }

  /**
   * Creates an Emulsify sub-theme.
   */
  #[CLI\Command(name: 'emulsify_tools:bake', aliases: ['emulsify'])]
  #[CLI\Argument(name: 'name', description: 'The name of your emulsify based subtheme.')]
  #[CLI\Usage(name: 'emulsify_tools:bake MyThemeName')]
  public function generateSubTheme(string $name): CollectionBuilder {
    $machineName = $this->convertLabelToMachineName($name);
    $sourceDirectory = $this->getStarterRecipeDirectory();
    $destinationDirectory = "themes/custom/{$machineName}";

    $builder = $this->collectionBuilder();
    $builder->getState()->offsetSet('srcDir', $sourceDirectory);
    $builder->addTask($this->taskTmpDir());

    if (UrlHelper::isValid($sourceDirectory, TRUE)) {
      $builder->addCode(fn (RoboStateData $data): int => $this->downloadStarterRecipe($data, $sourceDirectory));
      $builder->addCode(fn (RoboStateData $data): int => $this->extractStarterRecipe($data));
    }

    $builder->addCode(fn (RoboStateData $data): int => $this->copyStarterRecipe($data, $destinationDirectory));
    $builder->addCode(fn (): int => $this->customizeStarterRecipe($name, $machineName, $destinationDirectory));

    return $builder;
  }

  /**
   * Repairs child theme favicon install and schema files for Emulsify 7.x.
   */
  #[CLI\Command(name: 'emulsify_tools:repair-favicon-config')]
  #[CLI\Argument(name: 'theme', description: 'Optional Emulsify-based child theme machine name.')]
  #[CLI\Usage(name: 'emulsify_tools:repair-favicon-config')]
  #[CLI\Usage(name: 'emulsify_tools:repair-favicon-config my_child_theme')]
  public function repairFaviconConfig(?string $theme = NULL): int {
    try {
      $result = $this->childThemeFaviconConfigRepairer->repair($theme);
    }
    catch (\InvalidArgumentException $exception) {
      $this->logger()->error($exception->getMessage());
      return 1;
    }
    catch (\Throwable $exception) {
      $this->logger()->error($exception->getMessage());
      return 1;
    }

    foreach ($result['updated_themes'] as $themeName => $themeResult) {
      $this->logger()->notice(sprintf(
        'Updated %s (%s): install=%s, schema=%s.',
        $themeName,
        $themeResult['path'],
        $themeResult['install'],
        $themeResult['schema'],
      ));
    }

    foreach ($result['errors'] as $themeName => $message) {
      $this->logger()->error(sprintf('Unable to repair %s: %s', $themeName, $message));
    }

    if ($result['updated_count'] === 0 && $result['errors'] === []) {
      $this->logger()->notice('No Emulsify child theme favicon source files needed repair.');
    }

    $this->logger()->notice(sprintf(
      'Inspected %d Emulsify-based child themes: %d updated, %d unchanged, %d errors.',
      $result['inspected_count'],
      $result['updated_count'],
      $result['unchanged_count'],
      count($result['errors']),
    ));

    return $result['errors'] === [] ? 0 : 1;
  }

  /**
   * Convert label to machine name.
   *
   * @param string $label
   *   The label.
   *
   * @return string
   *   The machine name.
   */
  private function convertLabelToMachineName(string $label): string {
    $machineName = preg_replace('/[^a-z0-9_]+/ui', '_', $label);
    if ($machineName === NULL) {
      throw new \RuntimeException(sprintf('Unable to convert "%s" to a machine name.', $label));
    }

    $machineName = trim(mb_strtolower($machineName), '_');
    if ($machineName === '') {
      throw new \InvalidArgumentException('Theme name must contain at least one alphanumeric character.');
    }

    return $machineName;
  }

  /**
   * Resolves the Emulsify starter recipe directory.
   *
   * @return string
   *   The starter recipe directory.
   */
  private function getStarterRecipeDirectory(): string {
    $emulsifyDirectory = $this->themeExtensionList->getPath('emulsify');
    if ($emulsifyDirectory === '') {
      throw new \RuntimeException('The Emulsify base theme could not be found.');
    }

    return $emulsifyDirectory . '/whisk';
  }

  /**
   * Downloads a remote starter recipe archive.
   *
   * @param \Robo\State\Data $data
   *   The Robo state bag.
   * @param string $sourceDirectory
   *   The remote archive URL.
   *
   * @return int
   *   Zero on success, non-zero on failure.
   */
  private function downloadStarterRecipe(RoboStateData $data, string $sourceDirectory): int {
    $this->logger()->debug(
      'download Emulsify recipe from <info>{recipeUrl}</info>',
      ['recipeUrl' => $sourceDirectory],
    );

    $fileName = $this->getFileNameFromUrl($sourceDirectory);
    $packageDirectory = "{$data['path']}/pack";
    $data['packPath'] = "{$packageDirectory}/{$fileName}";

    try {
      $this->filesystem->mkdir($packageDirectory);
      $this->filesystem->copy($sourceDirectory, $data['packPath']);
    }
    catch (\Exception $exception) {
      $this->logger()->error($exception->getMessage());
      return 1;
    }

    return 0;
  }

  /**
   * Extracts a downloaded starter recipe archive.
   *
   * @param \Robo\State\Data $data
   *   The Robo state bag.
   *
   * @return int
   *   Zero on success, non-zero on failure.
   */
  private function extractStarterRecipe(RoboStateData $data): int {
    $this->logger()->debug(
      'extract downloaded Emulsify starter recipe from <info>{packPath}</info> to <info>{srcDir}</info>',
      [
        'packPath' => $data['packPath'],
        'srcDir' => "{$data['path']}/recipe",
      ],
    );

    $data['srcDir'] = "{$data['path']}/recipe";

    try {
      /** @var \Drupal\Core\Archiver\ArchiverInterface $extractorInstance */
      $extractorInstance = $this->archiverManager->getInstance(['filepath' => $data['packPath']]);
      $extractorInstance->extract($data['srcDir']);
    }
    catch (\Exception $exception) {
      $this->logger()->error($exception->getMessage());
      return 1;
    }

    $topLevelDirectory = $this->getTopLevelDirectory($data['srcDir']);
    if ($topLevelDirectory !== '') {
      $data['srcDir'] = $topLevelDirectory;
    }

    return 0;
  }

  /**
   * Copies the starter recipe into the destination theme directory.
   *
   * @param \Robo\State\Data $data
   *   The Robo state bag.
   * @param string $destinationDirectory
   *   The destination directory.
   *
   * @return int
   *   Zero on success, non-zero on failure.
   */
  private function copyStarterRecipe(RoboStateData $data, string $destinationDirectory): int {
    $this->logger()->debug(
      'copy Emulsify starter recipe from <info>{srcDir}</info> to <info>{dstDir}</info>',
      [
        'srcDir' => $data['srcDir'],
        'dstDir' => $destinationDirectory,
      ],
    );

    if ($this->filesystem->exists($destinationDirectory)) {
      $this->logger()->error(sprintf('Destination directory "%s" already exists.', $destinationDirectory));
      return 1;
    }

    try {
      $this->filesystem->mirror($data['srcDir'], $destinationDirectory);
    }
    catch (\Exception $exception) {
      $this->logger()->error($exception->getMessage());
      return 1;
    }

    return 0;
  }

  /**
   * Applies Emulsify-specific replacements to the copied starter recipe.
   *
   * @param string $name
   *   The theme label.
   * @param string $machineName
   *   The theme machine name.
   * @param string $destinationDirectory
   *   The copied destination directory.
   *
   * @return int
   *   Zero on success.
   */
  private function customizeStarterRecipe(string $name, string $machineName, string $destinationDirectory): int {
    $this->logger()->debug(
      'customize Emulsify starter recipe in <info>{dstDir}</info> directory',
      ['dstDir' => $destinationDirectory],
    );

    $this->subThemeGenerator->generate($destinationDirectory, $machineName, $name);

    return 0;
  }

  /**
   * Get directory descendants.
   *
   * @return \Symfony\Component\Finder\Finder
   *   The finder.
   */
  private function getDirectDescendants(string $dir): Finder {
    return new Finder()
      ->in($dir)
      ->depth('== 0');
  }

  /**
   * Get file name from URL.
   *
   * @param string $url
   *   The url.
   *
   * @return string
   *   The file name.
   */
  private function getFileNameFromUrl(string $url): string {
    $path = parse_url($url, PHP_URL_PATH);
    return pathinfo(is_string($path) ? $path : '', PATHINFO_BASENAME);
  }

  /**
   * Get the top level dir.
   *
   * @param string $parentDir
   *   The parent directory.
   *
   * @return string
   *   The top level directory.
   */
  private function getTopLevelDirectory(string $parentDir): string {
    $directDescendants = $this->getDirectDescendants($parentDir);
    if ($directDescendants->count() !== 1) {
      return '';
    }

    $iterator = $directDescendants->getIterator();
    $iterator->rewind();
    $firstFile = $iterator->current();
    if ($firstFile instanceof SplFileInfo && $firstFile->isDir()) {
      return $firstFile->getPathname();
    }

    return '';
  }

}
