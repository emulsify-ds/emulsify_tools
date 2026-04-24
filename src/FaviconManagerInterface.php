<?php

namespace Drupal\emulsify_tools;

/**
 * Favicon manager interface.
 */
interface FaviconManagerInterface {

  /**
   * Get tags for a theme as a string.
   *
   * @param string $theme_id
   *   The theme id.
   *
   * @return string|null
   *   The tags as HTML ready for output.
   */
  public function getTags($theme_id);

  /**
   * Get the Favicon Package entity associated with a theme.
   *
   * @param string $theme_id
   *   The theme id.
   *
   * @return \Drupal\emulsify_tools\Entity\FaviconPackageInterface|null
   *   The Favicon Package entity.
   */
  public function loadFavicon($theme_id);

  /**
   * Get the cache tags for favicons.
   */
  public function getCacheTags();

}
