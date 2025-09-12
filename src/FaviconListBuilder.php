<?php

namespace Drupal\emulsify_tools;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;

/**
 * Provides a listing of Favicon entities.
 */
class FaviconListBuilder extends ConfigEntityListBuilder {

  protected $themeHandler;

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('theme_handler')
    );
  }

  public function __construct(EntityTypeInterface $entity_type, EntityStorageInterface $storage, ThemeHandlerInterface $theme_handler) {
    $this->entityTypeId = $entity_type->id();
    $this->storage = $storage;
    $this->entityType = $entity_type;
    $this->themeHandler = $theme_handler;
  }

  public function buildHeader() {
    $header['image'] = '';
    $header['label'] = $this->t('Name');
    $header['id'] = $this->t('ID');
    return $header + parent::buildHeader();
  }

  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\emulsify_tools\Entity\FaviconInterface $entity */
    $row['image'] = [
      'data' => [
        '#theme' => 'image',
        '#uri' => $entity->getThumbnail(),
      ],
    ];
    $row['label'] = $entity->label();
    $row['id'] = $entity->id();
    return $row + parent::buildRow($entity);
  }

  public function render() {
    $render = parent::render();

    $favicon_options = [];
    foreach ($this->load() as $favicon) {
      $favicon_options[$favicon->id()] = $favicon->label();
    }

    if (!empty($favicon_options)) {
      $themes = $themes = $this->themeHandler->listInfo();
      uasort($themes, 'Drupal\\Core\\Extension\\ExtensionList::sortByName');

      $theme_options = [];
      foreach ($themes as &$theme) {
        if (!empty($theme->info['hidden'])) {
          continue;
        }
        if (!empty($theme->status)) {
          $theme_options[$theme->getName()] = $theme->info['name'];
        }
      }
      $render['form'] = \Drupal::formBuilder()->getForm('Drupal\\emulsify_tools\\Form\\FaviconSettingsForm', $favicon_options, $theme_options);
    }

    return $render;
  }
}


