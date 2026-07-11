<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools\Drush\Commands;

use Drupal\Core\Command\GenerateTheme;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\emulsify_tools\Favicon\ChildThemeFaviconConfigRepairer;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

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
    private readonly ChildThemeFaviconConfigRepairer $childThemeFaviconConfigRepairer,
    private readonly string $appRoot = \DRUPAL_ROOT,
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
   *   The Drupal core generator exit code.
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

    $input = new ArrayInput([
      'machine-name' => $machineName,
      '--name' => $themeName,
      '--description' => $description,
      '--starterkit' => 'whisk',
      '--path' => 'themes/custom',
    ]);
    $input->setInteractive(FALSE);
    $output = new BufferedOutput();
    $workingDirectory = getcwd();

    try {
      $exitCode = (new GenerateTheme(NULL, $this->appRoot))->run($input, $output);
    }
    catch (\Throwable $exception) {
      $this->logger()->error($exception->getMessage());
      return 1;
    }
    finally {
      if ($workingDirectory !== FALSE) {
        chdir($workingDirectory);
      }
    }

    $message = trim($output->fetch());
    if ($message !== '') {
      $exitCode === 0
        ? $this->logger()->notice($message)
        : $this->logger()->error($message);
    }

    return $exitCode;
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
