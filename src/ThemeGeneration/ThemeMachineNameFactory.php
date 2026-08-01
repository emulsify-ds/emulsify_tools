<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools\ThemeGeneration;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Language\LanguageInterface;

/**
 * Resolves and validates Drupal theme machine names from user input.
 */
final class ThemeMachineNameFactory {

  /**
   * The Emulsify base theme machine name.
   */
  private const EMULSIFY_THEME = 'emulsify';

  /**
   * Creates a theme machine-name factory.
   */
  public function __construct(
    private readonly TransliterationInterface $transliteration,
    private readonly ThemeExtensionList $themeExtensionList,
  ) {}

  /**
   * Resolves and validates a theme machine name.
   */
  public function create(string $input): ThemeMachineName {
    $transliterated = $this->transliteration->transliterate(
      trim($input),
      LanguageInterface::LANGCODE_DEFAULT,
      '_',
    );
    $machineName = preg_replace('/[^a-z0-9_]+/', '_', strtolower($transliterated));
    if ($machineName === NULL) {
      throw new \RuntimeException(sprintf('Unable to convert "%s" to a machine name.', $input));
    }

    $collapsedName = preg_replace('/_+/', '_', $machineName);
    if ($collapsedName === NULL) {
      throw new \RuntimeException(sprintf('Unable to convert "%s" to a machine name.', $input));
    }
    $machineName = trim($collapsedName, '_');
    if (preg_match('/[a-z0-9]/', $machineName) !== 1) {
      throw new \InvalidArgumentException(sprintf(
        'Theme name "%s" could not be converted to a valid Drupal machine name. Enter a name containing letters or numbers, for example "My Project Theme".',
        $input,
      ));
    }

    if (preg_match('/^[a-z]/', $machineName) !== 1) {
      throw new \InvalidArgumentException(sprintf(
        'Theme machine name "%s" derived from "%s" must start with a lowercase letter. Start the theme name with a letter, for example "my_theme".',
        $machineName,
        $input,
      ));
    }

    if (strlen($machineName) > \DRUPAL_EXTENSION_NAME_MAX_LENGTH) {
      throw new \InvalidArgumentException(sprintf(
        'Theme machine name "%s" is %d characters long, but Drupal theme machine names must be %d characters or fewer. Choose a shorter name, for example "my_project_theme".',
        $machineName,
        strlen($machineName),
        \DRUPAL_EXTENSION_NAME_MAX_LENGTH,
      ));
    }

    if ($machineName === self::EMULSIFY_THEME) {
      throw new \InvalidArgumentException('Theme machine name "emulsify" is reserved by the Emulsify base theme. Choose a unique child theme name, for example "my_project_theme".');
    }

    if ($this->themeExtensionList->exists($machineName)) {
      throw new \InvalidArgumentException(sprintf(
        'Theme machine name "%s" is already used by an existing Drupal theme. Choose a unique child theme name, for example "my_project_theme".',
        $machineName,
      ));
    }

    return new ThemeMachineName($input, $machineName);
  }

}
