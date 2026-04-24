<?php

namespace Drupal\emulsify_tools\Form;

use Drupal\Component\Utility\Environment;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form for Favicon Package entity.
 */
class FaviconPackageForm extends EntityForm {

  /**
   * The file entity.
   *
   * @var \Drupal\file\FileInterface
   */
  protected $file;

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    /** @var \Drupal\emulsify_tools\Entity\FaviconPackageInterface $entity */
    $entity = $this->entity;
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $entity->label(),
      '#description' => $this->t("Label for the Favicon Package."),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $entity->id(),
      '#machine_name' => [
        'exists' => '\Drupal\emulsify_tools\Entity\FaviconPackage::load',
        'replace_pattern' => '[^a-z0-9-]+',
        'replace' => '-',
      ],
      '#disabled' => !$entity->isNew(),
    ];

    $form['tags'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Tags'),
      '#default_value' => $entity->getTagsAsString(),
      '#description' => $this->t('Paste the code provided by <a href="@url" target="_blank">@url</a>. Make sure each link is on a separate line. It is fine to paste links with paths like "/apple-touch-icon-57x57.png" as these will be converted to the correct paths automatically.', ['@url' => 'http://realfavicongenerator.net/']),
      '#required' => TRUE,
    ];

    $validators = [
      'file_validate_extensions' => ['zip'],
      'file_validate_size' => [Environment::getUploadMaxSize()],
    ];
    $form['file'] = [
      '#type' => 'file',
      '#title' => $this->t('Upload a zip file from realfavicongenerator.net to install'),
      '#description' => [
        '#theme' => 'file_upload_help',
        '#description' => $this->t('For example: %filename from your local computer. This only needs to be done once.', ['%filename' => 'favicons.zip']),
        '#upload_validators' => $validators,
      ],
      '#size' => 50,
      '#upload_validators' => $validators,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    $this->file = file_save_upload('file', $form['file']['#upload_validators'], FALSE, 0);
    // Ensure we have the file uploaded.
    if (!$this->file && $this->entity->isNew()) {
      $form_state->setErrorByName('file', $this->t('File to import not found.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\emulsify_tools\Entity\FaviconPackageInterface $entity */
    $entity = $this->entity;

    if ($this->file) {
      try {
        $zip_path = $this->file->getFileUri();
        $entity->setArchive($zip_path);
      }
      catch (\Exception $e) {
        $form_state->setErrorByName('file', $e->getMessage());
        return;
      }
    }

    $status = $entity->save();

    switch ($status) {
      case SAVED_NEW:
        \Drupal::messenger()->addMessage($this->t('Created the %label Favicon Package.', [
          '%label' => $entity->label(),
        ]));
        break;

      default:
        \Drupal::messenger()->addMessage($this->t('Saved the %label Favicon Package.', [
          '%label' => $entity->label(),
        ]));
    }
    $form_state->setRedirectUrl($entity->toUrl('collection'));
  }

}
