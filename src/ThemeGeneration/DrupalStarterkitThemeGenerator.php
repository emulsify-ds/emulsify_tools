<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools\ThemeGeneration;

use Drupal\Core\Command\GenerateTheme;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Generates themes through Drupal core's Starterkit command.
 */
final class DrupalStarterkitThemeGenerator implements ThemeGeneratorInterface {

  /**
   * Creates a Drupal Starterkit theme generator.
   */
  public function __construct(
    private readonly string $appRoot,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function generate(ThemeGenerationRequest $request): ThemeGenerationResult {
    $input = new ArrayInput([
      'machine-name' => $request->machineName,
      '--name' => $request->displayName,
      '--description' => $request->description,
      '--starterkit' => $request->starterkitMachineName,
      '--path' => $request->destinationPath,
    ]);
    $input->setInteractive(FALSE);
    $output = new BufferedOutput();
    $workingDirectory = getcwd();

    try {
      try {
        $exitCode = (new GenerateTheme(NULL, $this->appRoot))->run($input, $output);
      }
      catch (\Throwable $exception) {
        return new ThemeGenerationResult(1, [$exception->getMessage()]);
      }

      $message = trim($output->fetch());

      return new ThemeGenerationResult(
        $exitCode,
        $message === '' ? [] : [$message],
        $exitCode === 0
          ? trim($request->destinationPath, '/') . '/' . $request->machineName
          : NULL,
      );
    }
    finally {
      if ($workingDirectory !== FALSE) {
        chdir($workingDirectory);
      }
    }
  }

}
