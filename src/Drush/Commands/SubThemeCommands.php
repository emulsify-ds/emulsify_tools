<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools\Drush\Commands;

use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\emulsify_tools\ThemeGeneration\ThemeGenerationRequest;
use Drupal\emulsify_tools\ThemeGeneration\ThemeGeneratorInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Provides Drush commands for Emulsify tools.
 */
final class SubThemeCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * The Emulsify base theme machine name.
   */
  private const EMULSIFY_THEME = 'emulsify';

  /**
   * Creates the command.
   */
  public function __construct(
    private readonly ThemeExtensionList $themeExtensionList,
    private readonly ThemeGeneratorInterface $themeGenerator,
  ) {
    parent::__construct();
  }

  /**
   * Creates an Emulsify child theme.
   *
   * @param string $name
   *   The theme machine name or a label that can be normalized into one.
   * @param array{name?: string|null, description?: string|null} $options
   *   Drupal Starterkit name and description options.
   *
   * @return int
   *   The selected theme generator exit code.
   */
  #[CLI\Command(name: 'emulsify_tools:bake', aliases: ['emulsify'])]
  #[CLI\Argument(name: 'name', description: 'The name of your Emulsify-based child theme.')]
  #[CLI\Option(name: 'name', description: 'The human-readable theme name. Defaults to the argument value.')]
  #[CLI\Option(name: 'description', description: 'A description of the generated theme.')]
  #[CLI\Usage(name: 'emulsify_tools:bake my_theme --name="My Theme" --description="Project theme"')]
  public function generateSubTheme(
    string $name,
    array $options = ['name' => NULL, 'description' => ''],
  ): int {
    $machineName = $this->convertLabelToMachineName($name);
    $this->logResolvedMachineName($name, $machineName);

    $themeName = is_string($options['name'] ?? NULL)
      ? $options['name']
      : $name;
    $description = is_string($options['description'] ?? NULL)
      ? $options['description']
      : '';

    $result = $this->themeGenerator->generate(new ThemeGenerationRequest(
      $machineName,
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

    $this->validateMachineName($machineName, $label);

    return $machineName;
  }

  /**
   * Validates a Drupal theme machine name.
   *
   * @throws \InvalidArgumentException
   *   Thrown when the machine name cannot be used for a child theme.
   */
  private function validateMachineName(string $machineName, string $label): void {
    if (preg_match('/^[a-z]/', $machineName) !== 1) {
      throw new \InvalidArgumentException(sprintf(
        'Theme machine name "%s" derived from "%s" must start with a lowercase letter. Start the theme name with a letter, for example "my_theme".',
        $machineName,
        $label,
      ));
    }

    if (strlen($machineName) > \DRUPAL_EXTENSION_NAME_MAX_LENGTH) {
      throw new \InvalidArgumentException(sprintf(
        'Theme machine name "%s" is %d characters long, but Drupal theme machine names must be %d characters or fewer. Choose a shorter name.',
        $machineName,
        strlen($machineName),
        \DRUPAL_EXTENSION_NAME_MAX_LENGTH,
      ));
    }

    if ($machineName === self::EMULSIFY_THEME) {
      throw new \InvalidArgumentException('Theme machine name "emulsify" is reserved by the Emulsify base theme. Choose a unique child theme name.');
    }

    if (isset($this->themeExtensionList->getList()[$machineName])) {
      throw new \InvalidArgumentException(sprintf(
        'Theme machine name "%s" is already used by an existing Drupal theme. Choose a unique child theme name.',
        $machineName,
      ));
    }
  }

  /**
   * Logs when a human-readable label resolves to a different machine name.
   */
  private function logResolvedMachineName(string $name, string $machineName): void {
    if ($machineName === trim($name)) {
      return;
    }

    $this->logger()->notice(sprintf(
      'Using "%s" as the Drupal theme machine name for "%s".',
      $machineName,
      $name,
    ));
  }

}
