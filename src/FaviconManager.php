<?php

namespace Drupal\emulsify_tools;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\Cache;

/**
 * The Emulsify Tools Favicon manager.
 */
class FaviconManager implements FaviconManagerInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  public $entityTypeManager;

  /**
   * The favicon configuration.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The cache id.
   *
   * @var string
   */
  protected $cid = 'emulsify_tools.favicon';

  /**
   * The cache tags.
   *
   * @var array
   */
  protected $cacheTags = [
    'config:emulsify_tools_favicon.settings',
    'config:emulsify_tools_favicon_list',
  ];

  /**
   * Constructs a new FaviconManager object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory, CacheBackendInterface $cache) {
    $this->entityTypeManager = $entity_type_manager;
    $this->config = $config_factory->get('emulsify_tools_favicon.settings');
    $this->cache = $cache;
  }

  /**
   * {@inheritdoc}
   */
  public function getTags($theme_id) {
    $tags = NULL;
    $enabled = (array) $this->config->get('themes');
    if (!empty($enabled[$theme_id])) {
      $cid = $this->cid . '.tags.' . $theme_id;
      if ($cache = $this->cache->get($cid)) {
        $tags = $cache->data;
      }
      else {
        if ($favicon = $this->loadFavicon($theme_id)) {
          $tags = $favicon->getValidTagsAsString();
        }
        $this->cache->set($cid, $tags, Cache::PERMANENT, $this->cacheTags);
      }
    }
    return $tags;
  }

  /**
   * {@inheritdoc}
   */
  public function loadFavicon($theme_id) {
    $favicon = NULL;
    $enabled = (array) $this->config->get('themes');
    if (!empty($enabled[$theme_id])) {
      $favicon = $this->entityTypeManager->getStorage('emulsify_tools_favicon')->load($enabled[$theme_id]);
    }
    return $favicon;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return $this->cacheTags;
  }

}


