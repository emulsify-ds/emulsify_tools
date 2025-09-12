<?php

namespace Drupal\emulsify_tools;

/**
 * Favicon manager interface.
 */
interface FaviconManagerInterface {

  /**
   * Get cache tags for a theme as a string.
   *
   * @param string $theme_id
   *   The theme id.
   *
   * @return string|null
   *   The tags as HTML ready for output.
   */
  public function getTags($theme_id);

  /**
   * Get the Favicon entity associated with a theme.
   *
   * @param string $theme_id
   *   The theme id.
   *
   * @return \Drupal\emulsify_tools\Entity\Favicon|null
   *   The Favicon entity.
   */
  public function loadFavicon($theme_id);

  /**
   * Get the cache tags for a theme favicon.
   */
  public function getCacheTags();

}


