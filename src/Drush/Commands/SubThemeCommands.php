<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools\Drush\Commands;

use Drupal\emulsify_tools\ThemeGeneration\ThemeGenerationRequest;
use Drupal\emulsify_tools\ThemeGeneration\ThemeGeneratorInterface;
use Drupal\emulsify_tools\ThemeGeneration\ThemeMachineName;
use Drupal\emulsify_tools\ThemeGeneration\ThemeMachineNameFactory;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Provides Drush commands for Emulsify tools.
 */
final class SubThemeCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Creates the command.
   */
  public function __construct(
    private readonly ThemeMachineNameFactory $themeMachineNameFactory,
    private readonly ThemeGeneratorInterface $themeGenerator,
  ) {
    parent::__construct();
  }

  /**
   * Creates an Emulsify child theme.
   *
   * @param string $name
   *   Positional machine name or label used to derive the machine name.
   * @param array{name?: string|null, description?: string|null} $options
   *   Display-name and description options for the generated theme.
   *
   * @return int
   *   The selected theme generator exit code.
   */
  #[CLI\Command(name: 'emulsify_tools:bake', aliases: ['emulsify', 'emulsify_tools:generate-theme'])]
  #[CLI\Help(
    description: 'Generate an Emulsify child theme.',
    synopsis: 'Pass a machine name or label as the positional value; use --name to set the human-readable display name.',
  )]
  #[CLI\Argument(name: 'name', description: 'Positional machine name or label used to derive the Drupal theme machine name.')]
  #[CLI\Option(name: 'name', description: 'Human-readable display name for the generated theme. Defaults to the positional value.')]
  #[CLI\Option(name: 'description', description: 'A description of the generated theme.')]
  #[CLI\Usage(name: 'emulsify_tools:bake my_theme --name="My Theme" --description="Project theme"')]
  #[CLI\Usage(name: 'emulsify_tools:generate-theme "My Theme"')]
  public function generateSubTheme(
    string $name,
    array $options = ['name' => NULL, 'description' => ''],
  ): int {
    $resolvedName = $this->themeMachineNameFactory->create($name);
    $this->logResolvedMachineName($resolvedName);

    $themeName = is_string($options['name'] ?? NULL)
      ? $options['name']
      : $name;
    $description = is_string($options['description'] ?? NULL)
      ? $options['description']
      : '';

    $result = $this->themeGenerator->generate(new ThemeGenerationRequest(
      $resolvedName->machineName,
      $themeName,
      $description,
      'whisk',
      'themes/custom',
    ));

    foreach ($result->warnings as $warning) {
      $this->logger()->warning($warning);
    }

    foreach ($result->messages as $message) {
      $result->exitCode === 0
        ? $this->logger()->notice($message)
        : $this->logger()->error($message);
    }

    return $result->exitCode;
  }

  /**
   * Logs when a human-readable label resolves to a different machine name.
   */
  private function logResolvedMachineName(ThemeMachineName $resolvedName): void {
    if (!$resolvedName->wasNormalized()) {
      return;
    }

    $this->logger()->notice(sprintf(
      'Using "%s" as the Drupal theme machine name for "%s".',
      $resolvedName->machineName,
      $resolvedName->originalInput,
    ));
  }

}
