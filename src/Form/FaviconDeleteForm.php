<?php

namespace Drupal\emulsify_tools\Form;

use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

class FaviconDeleteForm extends EntityConfirmFormBase {

  public function getQuestion() {
    return $this->t('Are you sure you want to delete %name?', ['%name' => $this->entity->label()]);
  }

  public function getCancelUrl() {
    return new Url('entity.emulsify_tools_favicon.collection');
  }

  public function getConfirmText() {
    return $this->t('Delete');
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->entity->delete();

    \Drupal::messenger()->addMessage(
      $this->t('content @type: deleted @label.',
        [
          '@type' => $this->entity->bundle(),
          '@label' => $this->entity->label(),
        ]
        )
    );

    $form_state->setRedirectUrl($this->getCancelUrl());
  }
}


