<?php

namespace Drupal\emulsify_tools\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Provides an interface for defining Favicon entities.
 */
interface FaviconInterface extends ConfigEntityInterface {

  /**
   * Return the location where icons exist.
   */
  public function getDirectory();

  /**
   * Get the tags as a string.
   */
  public function getTagsAsString();

  /**
   * Get a favicon image.
   */
  public function getThumbnail($image_name = 'favicon-16x16.png');

  /**
   * Set the archive as base64 encoded string.
   */
  public function setArchive($zip_path);

}


