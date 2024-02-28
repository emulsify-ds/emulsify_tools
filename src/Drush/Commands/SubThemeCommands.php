<?php

declare(strict_types = 1);

namespace Drupal\emulsify_tools\Drush\Commands;

use Consolidation\AnnotatedCommand\CommandData;
use Consolidation\AnnotatedCommand\CommandError;
use Drupal\Component\Utility\UrlHelper;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Robo\Contract\BuilderAwareInterface;
use Robo\State\Data as RoboStateData;
use Robo\TaskAccessor;
use Symfony\Component\Filesystem\Filesystem;

use Robo\Task\Archive\Tasks as ArchiveTaskLoader;
use Robo\Task\Filesystem\Tasks as FilesystemTaskLoader;
use Symfony\Component\Finder\Finder;

/**
 * A Drush commandfile.
 *
 * In addition to this file, you need a drush.services.yml
 * in root of your module, and a composer.json file that provides the name
 * of the services file to use.
 */
class SubThemeCommands extends DrushCommands implements BuilderAwareInterface {

  use TaskAccessor;
  use ArchiveTaskLoader;
  use FilesystemTaskLoader;

  /**
   * The emulsify subtheme generator.
   *
   * @var \Drupal\emulsify\Commands\SubThemeGenerator
   */
  protected $subThemeCreator;

  /**
   * The filesystem.
   *
   * @var \Symfony\Component\Filesystem\Filesystem
   */
  protected $fs;

  /**
   * {@inheritdoc}
   */
  public function __construct(?SubThemeGenerator $subThemeCreator = NULL, ?Filesystem $fs = NULL) {
    $this->subThemeCreator = $subThemeCreator ?: new SubThemeGenerator();
    $this->fs = $fs ?: new Filesystem();

    parent::__construct();
  }

  /**
   * Creates an Emulsify sub-theme.
   *
   */
  #[CLI\Command(name: 'emulsify_tools:bake', aliases: ['emulsify'])]
  #[CLI\Argument(name: 'name')]
  #[CLI\Usage(name: 'emulsify_tools:bake MyThemeName')]
  public function generateSubTheme(
    string $name,
  ) {
    $machineName = $this->convertLabelToMachineName($name);
    $emulsifyDir = \Drupal::service('extension.list.theme')->getPath('emulsify');
    $srcDir = $emulsifyDir . "/whisk";
    $dstDir = "themes/custom/{$machineName}";

    $cb = $this->collectionBuilder();
    $cb->getState()->offsetSet('srcDir', $srcDir);
    $cb->addTask($this->taskTmpDir());

    if (UrlHelper::isValid($srcDir, TRUE)) {
      $cb->addCode(function (RoboStateData $data) use ($srcDir): int {
        $logger = $this->logger();
        $logger->debug(
          'download Emulsify recipe from <info>{$srcDir}</info>',
          [
            'recipeUrl' => $srcDir,
          ]
        );

        $fileName = $this->getFileNameFromUrl($srcDir);
        $packDir = "{$data['path']}/pack";
        $data['packPath'] = "$packDir/$fileName";

        try {
          $this->fs->mkdir($packDir);
          $this->fs->copy($srcDir, $data['packPath']);
        }
        catch (\Exception $e) {
          $logger->error($e->getMessage());

          return 1;
        }

        return 0;
      });

      $cb->addCode(function (RoboStateData $data): int {
        $logger = $this->logger();
        $logger->debug(
          'extract downloaded Emulsify starter recipe from <info>{packPath}</info> to <info>{srcDir}</info>',
          [
            'packPath' => $data['packPath'],
            'srcDir' => $data['srcDir'],
          ]
        );

        $data['srcDir'] = "{$data['path']}/recipe";

        /** @var \Drupal\Core\Archiver\ArchiverManager $extractorManager */
        $extractorManager = \Drupal::service('plugin.manager.archiver');

        try {
          /** @var \Drupal\Core\Archiver\ArchiverInterface $extractorInstance */
          $extractorInstance = $extractorManager->getInstance(['filepath' => $data['packPath']]);
          $extractorInstance->extract($data['srcDir']);
        }
        catch (\Exception $e) {
          $this->logger()->error($e->getMessage());

          return 1;
        }

        $topLevelDir = $this->getTopLevelDir($data['srcDir']);
        if ($topLevelDir) {
          $data['srcDir'] = $topLevelDir;
        }

        return 0;
      });
    }

    $cb->addCode(function (RoboStateData $data) use ($dstDir): int {
      $logger = $this->logger();
      $logger->debug(
        'copy Emulsify starter recipe from <info>{srcDir}</info> to <info>{dstDir}</info>',
        [
          'srcDir' => $data['srcDir'],
          'dstDir' => $dstDir,
        ]
      );

      try {
        $this->fs->mirror($data['srcDir'], $dstDir);
      }
      catch (\Exception $e) {
        $this->logger()->error($e->getMessage());

        return 1;
      }

      return 0;
    });

    $cb->addCode(function () use ($name, $dstDir): int {
      $logger = $this->logger();
      $machineName = $this->convertLabelToMachineName($name);
      $logger->debug(
        'customize Emulsify starter recipe in <info>{dstDir}</info> directory',
        [
          'dstDir' => $dstDir,
        ]
      );

      $this
        ->subThemeCreator
        ->setDir($dstDir)
        ->setMachineName($machineName)
        ->setName($name)
        ->generate();

      return 0;
    });

    return $cb;
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
  protected function convertLabelToMachineName(string $label): string {
    return mb_strtolower(preg_replace('/[^a-z0-9_]+/ui', '_', $label));
  }

  /**
   * Get directory descendants.
   *
   * @return \Symfony\Component\Finder\Finder
   *   The finder.
   */
  protected function getDirectDescendants(string $dir): Finder {
    return (new Finder())
      ->in($dir)
      ->depth('0');
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
  protected function getFileNameFromUrl(string $url): string {
    return pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_BASENAME);
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
  protected function getTopLevelDir(string $parentDir): string {
    $directDescendants = $this->getDirectDescendants($parentDir);
    $iterator = $directDescendants->getIterator();
    $iterator->rewind();
    /** @var \Symfony\Component\Finder\SplFileInfo $firstFile */
    $firstFile = $iterator->current();
    if ($directDescendants->count() === 1 && $firstFile->isDir()) {
      return $firstFile->getPathname();
    }

    return '';
  }

}
