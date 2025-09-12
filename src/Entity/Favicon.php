<?php

namespace Drupal\emulsify_tools\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityMalformedException;
use Drupal\Core\File\FileSystemInterface;

/**
 * Defines the Favicon entity.
 *
 * @ConfigEntityType(
 *   id = "emulsify_tools_favicon",
 *   label = @Translation("Favicon"),
 *   handlers = {
 *     "list_builder" = "Drupal\emulsify_tools\FaviconListBuilder",
 *     "form" = {
 *       "add" = "Drupal\emulsify_tools\Form\FaviconForm",
 *       "edit" = "Drupal\emulsify_tools\Form\FaviconForm",
 *       "delete" = "Drupal\emulsify_tools\Form\FaviconDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\emulsify_tools\FaviconHtmlRouteProvider",
 *     },
 *   },
 *   config_prefix = "emulsify_tools_favicon",
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
 *     "canonical" = "/admin/structure/emulsify-tools-favicon/{emulsify_tools_favicon}",
 *     "add-form" = "/admin/structure/emulsify-tools-favicon/add",
 *     "edit-form" = "/admin/structure/emulsify-tools-favicon/{emulsify_tools_favicon}/edit",
 *     "delete-form" = "/admin/structure/emulsify-tools-favicon/{emulsify_tools_favicon}/delete",
 *     "collection" = "/admin/structure/emulsify-tools-favicon"
 *   }
 * )
 */
class Favicon extends ConfigEntityBase implements FaviconInterface {

  protected $id;
  protected $label;
  protected $manifest = [];
  protected $directory = 'public://favicon';

  public function setTagsAsString($string) {
    $tags = array_filter(explode(PHP_EOL, $string));
    foreach ($tags as $pos => $tag) {
      $tags[$pos] = trim($tag);
    }
    $this->set('tags', $tags);
  }

  public function getTagsAsString() {
    $tags = $this->get('tags');
    return $tags ? implode(PHP_EOL, $tags) : '';
  }

  public function getTags() {
    return $this->get('tags');
  }

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

  public function setArchive($zip_path) {
    $data = strtr(base64_encode(addslashes(gzcompress(serialize(file_get_contents($zip_path)), 9))), '+/=', '-_,');
    $parts = str_split($data, 200000);
    $this->set('archive', $parts);
  }

  public function getArchive() {
    $data = implode('', $this->get('archive'));
    return unserialize(gzuncompress(stripslashes(base64_decode(strtr($data, '-_,', '+/=')))));
  }

  public function getThumbnail($image_name = 'favicon-16x16.png') {
    return $this->getDirectory() . '/' . $image_name;
  }

  public function getDirectory() {
    return $this->directory . '/' . $this->id();
  }

  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);

    $original = NULL;
    if (!$this->isNew()) {
      /** @var \Drupal\emulsify_tools\Entity\FaviconInterface $original */
      $original = $storage->loadUnchanged($this->getOriginalId());
    }

    if (is_string($this->get('tags'))) {
      $this->setTagsAsString($this->get('tags'));
    }

    if (!$this->get('archive')) {
      throw new EntityMalformedException('Favicon package is required.');
    }
    if ($this->isNew() || ($original && $original->get('archive') !== $this->get('archive'))) {
      $this->archiveDecode();
    }
  }

  public static function preDelete(EntityStorageInterface $storage, array $entities) {
    parent::preDelete($storage, $entities);
    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $file_system = \Drupal::service('file_system');
    foreach ($entities as $entity) {
      /** @var \Drupal\emulsify_tools\Entity\FaviconInterface $entity */
      $file_system->deleteRecursive($entity->getDirectory());
      @rmdir($entity->directory);
    }
  }

  protected function archiveDecode() {
    $data = $this->getArchive();
    $zip_path = 'temporary://' . $this->id() . '.zip';
    file_put_contents($zip_path, $data);
    $this->archiveExtract($zip_path);
  }

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

    \Drupal::messenger()->addMessage(t('Favicon package has been successfully %op.', ['%op' => ($this->isNew() ? t('updated') : t('added'))]));
  }

  public function getValidTagsAsString() {
    return implode(PHP_EOL, $this->getValidTags()) . PHP_EOL;
  }

  public function getValidTags() {
    $base_path = base_path();
    $html = $this->getTagsAsString();
    $found = [];
    $missing = [];

    $dom = new \DOMDocument();
    $dom->loadHTML($html);

    $docroot = preg_replace('/' . preg_quote($base_path, '/') . '$/', '/', DRUPAL_ROOT);

    $tags = $dom->getElementsByTagName('link');
    foreach ($tags as $tag) {
      $file_path = $this->normalizePath($tag->getAttribute('href'));
      $tag->setAttribute('href', $file_path);

      if (file_exists($docroot . $file_path) && is_readable($docroot . $file_path)) {
        $found[] = $dom->saveXML($tag);
      }
      else {
        $missing[] = $dom->saveXML($tag);
      }
    }

    $tags = $dom->getElementsByTagName('meta');
    foreach ($tags as $tag) {
      $name = $tag->getAttribute('name');

      if ($name === 'msapplication-TileImage') {
        $file_path = $this->normalizePath($tag->getAttribute('content'));
        $tag->setAttribute('content', $file_path);

        if (file_exists($docroot . $file_path) && is_readable($docroot . $file_path)) {
          $found[] = $dom->saveXML($tag);
        }
        else {
          $missing[] = $dom->saveXML($tag);
        }
      }
      else {
        $found[] = $dom->saveXML($tag);
      }
    }
    return $found;
  }

  protected function normalizePath($file_path) {
    /** @var \Drupal\Core\File\FileUrlGeneratorInterface $url_generator */
    $url_generator = \Drupal::service('file_url_generator');
    return $url_generator->generateString($this->getDirectory() . $file_path);
  }

}


