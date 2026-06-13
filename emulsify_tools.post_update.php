<?php

/**
 * @file
 * Post update hooks for Emulsify Tools.
 */

declare(strict_types=1);

use Drupal\emulsify_tools\Favicon\FaviconThemeSettingsBackfill;

/**
 * Backfills favicon theme settings for installed Emulsify-based themes.
 */
function emulsify_tools_post_update_backfill_emulsify_favicon_settings(array &$sandbox = []): string {
  /** @var \Drupal\emulsify_tools\Favicon\FaviconThemeSettingsBackfill $backfill */
  $backfill = \Drupal::service(FaviconThemeSettingsBackfill::class);
  $result = $backfill->backfill();

  return (string) t(
    'Backfilled favicon settings for @updated of @affected installed Emulsify-based themes. This migrates only the active <theme>.settings config on the current site; older generated child themes still need their own default config and schema files updated in source so fresh installs and future exports include the new keys.',
    [
      '@updated' => (string) $result['updated_count'],
      '@affected' => (string) $result['affected_count'],
    ],
  );
}
