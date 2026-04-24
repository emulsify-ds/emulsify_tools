<?php

namespace Drupal\emulsify_tools\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityMalformedException;
use Drupal\Core\File\FileSystemInterface;

/**
 * Defines the Favicon Package entity.
 *
 * @ConfigEntityType(
 *   id = "favicon_package",
 *   label = @Translation("Favicon Package"),
 *   handlers = {
 *     "list_builder" = "Drupal\emulsify_tools\FaviconPackageListBuilder",
 *     "form" = {
 *       "add" = "Drupal\emulsify_tools\Form\FaviconPackageForm",
 *       "edit" = "Drupal\emulsify_tools\Form\FaviconPackageForm",
 *       "delete" = "Drupal\emulsify_tools\Form\FaviconPackageDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   config_prefix = "favicon_package",
 *   admin_permission = "administer site configuration",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "tags",
 *     "archive",
 *   },
 *   links = {
 *     "canonical" = "/admin/structure/favicon-package/{favicon_package}",
 *     "add-form" = "/admin/structure/favicon-package/add",
 *     "edit-form" = "/admin/structure/favicon-package/{favicon_package}/edit",
 *     "delete-form" = "/admin/structure/favicon-package/{favicon_package}/delete",
 *     "collection" = "/admin/structure/favicon-package"
 *   }
 * )
 */
class FaviconPackage extends ConfigEntityBase implements FaviconPackageInterface {

  /**
   * The Favicon Package ID.
   *
   * @var string
   */
  protected $id;

  /**
   * The Favicon Package label.
   *
   * @var string
   */
  protected $label;

  /**
   * The manifest of this package.
   *
   * @var array
   */
  protected $manifest = [];

  /**
   * The folder where Favicon Packages exist.
   *
   * @var string
   */
  protected $directory = 'public://favicon_packages';

  /**
   * Set the tags from string.
   */
  public function setTagsAsString($string) {
    $tags = array_filter(explode(PHP_EOL, $string));
    foreach ($tags as $pos => $tag) {
      $tags[$pos] = trim($tag);
    }
    $this->set('tags', $tags);
  }

  /**
   * {@inheritDoc}
   */
  public function getTagsAsString() {
    $tags = $this->get('tags');
    return $tags ? implode(PHP_EOL, $tags) : '';
  }

  /**
   * Get the tags.
   */
  public function getTags() {
    return $this->get('tags');
  }

  /**
   * Get the manifest.
   */
  public function getManifest() {
    if (empty($this->manifest)) {
      $this->manifest = [];
      $path = $this->getDirectory() . '/manifest.json';
      if (file_exists($path)) {
        $data = file_get_contents($path);
        $this->manifest = Json::decode($data);
      }
    }
    return $this->manifest;
  }

  /**
   * Get the largest manifest image.
   */
  public function getManifestLargeImage() {
    $image = '';
    if ($manifest = $this->getManifest()) {
      $size = 0;
      foreach ($manifest['icons'] as $icon) {
        $icon_size = explode('x', $icon['sizes']);
        if ($icon_size[0] > $size) {
          $image = $this->getDirectory() . $icon['src'];
        }
      }
    }
    else {
      return $this->getDirectory() . '/apple-touch-icon.png';
    }
    return $image;
  }

  /**
   * {@inheritDoc}
   */
  public function setArchive($zip_path) {
    $data = strtr(base64_encode(addslashes(gzcompress(serialize(file_get_contents($zip_path)), 9))), '+/=', '-_,');
    $parts = str_split($data, 200000);
    $this->set('archive', $parts);
  }

  /**
   * Get the archive from base64 encoded string.
   */
  public function getArchive() {
    $data = implode('', $this->get('archive'));
    return unserialize(gzuncompress(stripslashes(base64_decode(strtr($data, '-_,', '+/=')))));
  }

  /**
   * Get a favicon image.
   */
  public function getThumbnail($image_name = 'favicon-96x96.png') {
    return $this->getDirectory() . '/' . $image_name;
  }

  /**
   * Return the location where Favicon Packages exist.
   *
   * @return string
   *   The directory path.
   */
  public function getDirectory() {
    return $this->directory . '/' . $this->id();
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);

    if (!$this->isNew()) {
      $original = $storage->loadUnchanged($this->getOriginalId());
    }

    if (is_string($this->get('tags'))) {
      $this->setTagsAsString($this->get('tags'));
    }

    if (!$this->get('archive')) {
      throw new EntityMalformedException('Favicon package archive is required.');
    }
    if ($this->isNew() || (isset($original) && $original->get('archive') !== $this->get('archive'))) {
      $this->archiveDecode();
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function preDelete(EntityStorageInterface $storage, array $entities) {
    parent::preDelete($storage, $entities);
    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $file_system = \Drupal::service('file_system');
    foreach ($entities as $entity) {
      /** @var \Drupal\emulsify_tools\Entity\FaviconPackageInterface $entity */
      $file_system->deleteRecursive($entity->getDirectory());
      // Clean up empty directory. Will fail silently if it is not empty.
      @rmdir($entity->getDirectory());
    }
  }

  /**
   * Take base64 encoded archive and save it to a temporary file for extraction.
   */
  protected function archiveDecode() {
    $data = $this->getArchive();
    $zip_path = 'temporary://' . $this->id() . '.zip';
    file_put_contents($zip_path, $data);
    $this->archiveExtract($zip_path);
  }

  /**
   * Properly extract and store an archive.
   *
   * @param string $zip_path
   *   The absolute path to the zip file.
   */
  public function archiveExtract($zip_path) {
    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $file_system = \Drupal::service('file_system');
    /** @var \Drupal\Core\Archiver\ArchiverManager $archiver_manager */
    $archiver_manager = \Drupal::service('plugin.manager.archiver');
    $archiver = $archiver_manager->getInstance(['filepath' => $zip_path]);
    if (!$archiver) {
      throw new \Exception(t('Cannot extract %file, not a valid archive.', ['%file' => $zip_path]));
    }

    $directory = $this->getDirectory();
    $file_system->deleteRecursive($directory);
    $file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $archiver->extract($directory);

    \Drupal::messenger()->addMessage(t('Favicon package has been successfully %op.', ['%op' => ($this->isNew() ? t('added') : t('updated'))]));
  }

  /**
   * Get valid tags as strings.
   */
  public function getValidTagsAsString() {
    return implode(PHP_EOL, $this->getValidTags()) . PHP_EOL;
  }

  /**
   * Get valid tags.
   */
  public function getValidTags() {
    $base_path = base_path();
    $html = $this->getTagsAsString();
    $found = [];

    if (empty($html)) {
      return $found;
    }

    $dom = new \DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

    $base_path_normalized = preg_replace('/' . preg_quote($base_path, '/') . '$/', '/', DRUPAL_ROOT);

    // Find all the links.
    $tags = $dom->getElementsByTagName('link');
    foreach ($tags as $tag) {
      $href = $tag->getAttribute('href');
      if ($href) {
        $file_path = $this->normalizePath($href);
        $tag->setAttribute('href', $file_path);

        if (file_exists($base_path_normalized . $file_path) && is_readable($base_path_normalized . $file_path)) {
          $found[] = $dom->saveXML($tag);
        }
      }
    }

    // Find meta tags.
    $tags = $dom->getElementsByTagName('meta');
    foreach ($tags as $tag) {
      $name = $tag->getAttribute('name');

      if ($name === 'msapplication-TileImage') {
        $content = $tag->getAttribute('content');
        if ($content) {
          $file_path = $this->normalizePath($content);
          $tag->setAttribute('content', $file_path);

          if (file_exists($base_path_normalized . $file_path) && is_readable($base_path_normalized . $file_path)) {
            $found[] = $dom->saveXML($tag);
          }
        }
      }
      else {
        $found[] = $dom->saveXML($tag);
      }
    }
    return $found;
  }

  /**
   * Normalize path.
   *
   * @return string
   *   The normalized path.
   */
  protected function normalizePath($file_path) {
    /** @var \Drupal\Core\File\FileUrlGeneratorInterface $url_generator */
    $url_generator = \Drupal::service('file_url_generator');
    // Extract filename if it starts with a slash or is just a relative path.
    $filename = ltrim($file_path, '/');
    return $url_generator->generateString($this->getDirectory() . '/' . $filename);
  }

}
