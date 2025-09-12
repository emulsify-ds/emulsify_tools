<?php

namespace Drupal\emulsify_tools;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Symfony\Component\Routing\Route;

/**
 * Provides routes for Favicon entities.
 */
class FaviconHtmlRouteProvider extends AdminHtmlRouteProvider {

  public function getRoutes(EntityTypeInterface $entity_type) {
    $collection = parent::getRoutes($entity_type);
    $entity_type_id = $entity_type->id();
    if ($collection_route = $this->getCollectionRoute($entity_type)) {
      $collection->add("entity.{$entity_type_id}.collection", $collection_route);
    }
    return $collection;
  }

  protected function getCollectionRoute(EntityTypeInterface $entity_type) {
    if ($entity_type->hasLinkTemplate('collection') && $entity_type->hasListBuilderClass()) {
      $entity_type_id = $entity_type->id();
      $route = new Route($entity_type->getLinkTemplate('collection'));
      $route
        ->setDefaults([
          '_entity_list' => $entity_type_id,
          '_title' => 'Favicons',
        ])
        ->setRequirement('_permission', $entity_type->getAdminPermission())
        ->setOption('_admin_route', TRUE);
      return $route;
    }
  }
}


