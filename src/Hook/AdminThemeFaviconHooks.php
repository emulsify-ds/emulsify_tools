<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\emulsify_tools\AdminThemeFaviconManager;

/**
 * Hooks for applying Emulsify-generated favicons to admin themes.
 */
final class AdminThemeFaviconHooks {

  use StringTranslationTrait;

  /**
   * Creates the hook handler.
   */
  public function __construct(
    private readonly AdminThemeFaviconManager $adminThemeFaviconManager,
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Handles hook_form_system_theme_settings_alter().
   */
  #[Hook('form_system_theme_settings_alter')]
  public function formSystemThemeSettingsAlter(array &$form, FormStateInterface $form_state): void {
    $form['#after_build'][] = [self::class, 'addAdminThemeToggle'];
    $form['#submit'][] = [self::class, 'submitAdminThemeToggle'];
  }

  /**
   * Handles hook_page_attachments_alter().
   */
  #[Hook('page_attachments_alter')]
  public function pageAttachmentsAlter(array &$attachments): void {
    $this->adminThemeFaviconManager->applyToAdminPageAttachments($attachments);
  }

  /**
   * Static after-build callback for the Emulsify favicon settings fieldset.
   */
  public static function addAdminThemeToggle(array $form, FormStateInterface $form_state): array {
    return self::service()->doAddAdminThemeToggle($form, $form_state);
  }

  /**
   * Static submit callback for the admin-theme favicon toggle.
   */
  public static function submitAdminThemeToggle(array &$form, FormStateInterface $form_state): void {
    self::service()->doSubmitAdminThemeToggle($form_state);
  }

  /**
   * Adds the admin-theme toggle once the Emulsify form is fully built.
   */
  private function doAddAdminThemeToggle(array $form, FormStateInterface $form_state): array {
    if (empty($form['emulsify_favicon']) || !is_array($form['emulsify_favicon'])) {
      return $form;
    }

    $themeName = $this->resolveThemeName($form_state);
    $adminTheme = $this->adminThemeFaviconManager->getConfiguredAdminTheme();
    $description = $adminTheme !== ''
      ? $this->t('If checked, admin pages rendered with %theme will reuse this generated favicon package instead of the admin theme default.', ['%theme' => $adminTheme])
      : $this->t('If checked, admin pages rendered with a separate admin theme will reuse this generated favicon package.');

    $form['emulsify_favicon']['emulsify_tools_apply_admin_theme_favicon'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Apply generated favicon package to the admin theme'),
      '#default_value' => $this->adminThemeFaviconManager->isEnabledForTheme($themeName),
      '#description' => $description,
      '#weight' => 2,
      '#states' => [
        'visible' => [
          ':input[name="favicon_package_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return $form;
  }

  /**
   * Persists the admin-theme toggle for the configured frontend theme.
   */
  private function doSubmitAdminThemeToggle(FormStateInterface $form_state): void {
    $enabled = $form_state->getValue('emulsify_tools_apply_admin_theme_favicon');
    if ($enabled === NULL) {
      return;
    }

    $this->adminThemeFaviconManager->setEnabledForTheme(
      $this->resolveThemeName($form_state),
      (bool) $enabled,
    );
  }

  /**
   * Resolves the theme being configured on the system theme settings form.
   */
  private function resolveThemeName(FormStateInterface $form_state): string {
    $routeTheme = $this->routeMatch->getParameter('theme');
    if (is_string($routeTheme) && $routeTheme !== '') {
      return $routeTheme;
    }
    if (is_object($routeTheme) && method_exists($routeTheme, 'getName')) {
      return (string) $routeTheme->getName();
    }

    $args = $form_state->getBuildInfo()['args'] ?? [];
    if (!empty($args[0]) && is_string($args[0])) {
      return $args[0];
    }

    return 'emulsify';
  }

  /**
   * Resolves the autowired hook service for static FAPI callbacks.
   */
  private static function service(): self {
    return \Drupal::service('class_resolver')->getInstanceFromDefinition(self::class);
  }

}
