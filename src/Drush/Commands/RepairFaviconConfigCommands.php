<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools\Drush\Commands;

use Drupal\emulsify_tools\Favicon\ChildThemeFaviconConfigRepairer;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Provides the child-theme favicon config repair command.
 */
final class RepairFaviconConfigCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Creates the command class.
   */
  public function __construct(
    private readonly ChildThemeFaviconConfigRepairer $childThemeFaviconConfigRepairer,
  ) {
    parent::__construct();
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

}
