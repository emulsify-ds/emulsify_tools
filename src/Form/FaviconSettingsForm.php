<?php

namespace Drupal\emulsify_tools\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configures settings for Favicons.
 */
class FaviconSettingsForm extends ConfigFormBase {

  protected function getEditableConfigNames() {
    return [
      'emulsify_tools_favicon.settings',
    ];
  }

  public function getFormId() {
    return 'emulsify_tools_favicon_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $favicon_options = NULL, $theme_options = NULL) {
    $config = $this->config('emulsify_tools_favicon.settings');
    $config_themes = $config->get('themes');

    $form['themes'] = [
      '#type' => 'details',
      '#title' => $this->t('Theme Favicons'),
      '#description' => $this->t('A favicon can be set per theme.'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    foreach ($theme_options as $id => $name) {
      $form['themes'][$id] = [
        '#type' => 'select',
        '#title' => $this->t('@name Favicon', ['@name' => $name]),
        '#options' => [0 => '- Use Drupal Default -'] + $favicon_options,
        '#default_value' => !empty($config_themes[$id]) && isset($favicon_options[$config_themes[$id]]) ? $config_themes[$id] : 0,
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('emulsify_tools_favicon.settings');
    parent::submitForm($form, $form_state);

    $config
      ->set('themes', array_filter($form_state->getValue('themes')))
      ->save();
  }
}


