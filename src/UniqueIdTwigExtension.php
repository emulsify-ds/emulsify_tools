<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools;

use Drupal\Component\Utility\Crypt;
use Drupal\Component\Utility\Html;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension.
 */
final class UniqueIdTwigExtension extends AbstractExtension {

  /**
   * A set of unique IDs that have already been used on the same page.
   *
   * @var int[]
   */
  public $uniqueIds = [];

  /**
   * {@inheritdoc}
   */
  public function getFunctions(): array {
    return [
      new TwigFunction('uniqueId', [
        $this,
        'getUniqueId',
      ]),
    ];
  }

  /**
   * Generate a unique ID and verify it is unique from others on the same page.
   *
   * @var string $prepend
   *   An optional prepended string.
   *
   * @return string
   *   A prepended string combined with a random ID or just a random ID.
   */
  public function getUniqueId($prepend = NULL): string {
    // Generate unique number.
    $id = $this->generateRandomId();

    // Check if ID has already been used on the page.
    while (\in_array($id, $this->uniqueIds, TRUE)) {
      $id = $this->generateRandomId();
    }
    $this->uniqueIds[] = $id;

    $return = ($prepend) ? "$prepend-$id" : $id;

    return $return;

  }

  /**
   * Generate unique ID.
   */
  private function generateRandomId($length = 10) {
    $letters = 'abcdefghijklmnopqrstuvwxyz';
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';

    // First character must be a letter for DOM ids.
    $id = $letters[random_int(0, strlen($letters) - 1)];

    for ($i = 1; $i < $length; $i++) {
      $id .= $chars[random_int(0, strlen($chars) - 1)];
    }

    return $id;
  }

}
