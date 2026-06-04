<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools\Twig\Loader;

use Drupal\emulsify_tools\Twig\ThemeNamespaceRegistry;
use Twig\Error\LoaderError;
use Twig\Loader\FilesystemLoader;

/**
 * Loads Twig namespaces declared by the active Emulsify theme hierarchy.
 */
final class ThemeNamespaceLoader extends FilesystemLoader {

  /**
   * Creates the loader.
   */
  public function __construct(
    private readonly ThemeNamespaceRegistry $themeNamespaceRegistry,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function findTemplate(string $name, bool $throw = TRUE): ?string {
    $path = $this->themeNamespaceRegistry->getTemplate($name);

    if ($path !== NULL || !$throw) {
      return $path;
    }

    throw new LoaderError(
      ThemeNamespaceRegistry::isValidTemplateName($name)
        ? sprintf('Unable to find Twig namespace template "%s".', $name)
        : sprintf('Malformed namespaced template name "%s" (expecting "@namespace/template.twig").', $name),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function exists(string $name): bool {
    return $this->themeNamespaceRegistry->hasTemplate($name);
  }

}
