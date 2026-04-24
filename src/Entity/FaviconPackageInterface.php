<?php

namespace Drupal\emulsify_tools\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Provides an interface for defining Favicon Package entities.
 */
interface FaviconPackageInterface extends ConfigEntityInterface {

  /**
   * Return the location where favicon packages exist.
   *
   * @return string
   *   The directory path.
   */
  public function getDirectory();

  /**
   * Get the tags as a string.
   *
   * @return string
   *   The tags as a string.
   */
  public function getTagsAsString();

  /**
   * Get a favicon image.
   *
   * @return string
   *   The favicon image.
   */
  public function getThumbnail($image_name = 'favicon-96x96.png');

  /**
   * Set the archive as base64 encoded string.
   *
   * @param string $zip_path
   *   The zip path.
   */
  public function setArchive($zip_path);

}
