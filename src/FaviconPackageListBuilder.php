<?php

namespace Drupal\emulsify_tools;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityStorageInterface;

/**
 * Provides a listing of Favicon Package entities.
 */
class FaviconPackageListBuilder extends ConfigEntityListBuilder {

  /**
   * The theme handler service.
   *
   * @var \Drupal\Core\Extension\ThemeHandlerInterface
   */
  protected $themeHandler;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('theme_handler')
    );
  }

  /**
   * Constructs a new EntityListBuilder object.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   The entity storage class.
   * @param \Drupal\Core\Extension\ThemeHandlerInterface $theme_handler
   *   The theme handler.
   */
  public function __construct(EntityTypeInterface $entity_type, EntityStorageInterface $storage, ThemeHandlerInterface $theme_handler) {
    $this->entityTypeId = $entity_type->id();
    $this->storage = $storage;
    $this->entityType = $entity_type;
    $this->themeHandler = $theme_handler;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['image'] = $this->t('Preview Image');
    $header['label'] = $this->t('Name');
    $header['id'] = $this->t('ID');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\emulsify_tools\Entity\FaviconPackageInterface $entity */
    $row['image'] = [
      'data' => [
        '#theme' => 'image',
        '#uri' => $entity->getThumbnail(),
        '#alt' => $entity->label(),
        '#width' => 32,
        '#height' => 32,
      ],
    ];
    $row['label'] = $entity->label();
    $row['id'] = $entity->id();
    return $row + parent::buildRow($entity);
  }

}
