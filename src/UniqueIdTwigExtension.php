<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools;

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
   * Get a unique ID and make sure it is unique from others on the same page.
   *
   * @var string $prepend
   *   An optional prepended string.
   *
   * @return string|int
   *   The string combined with a random ID or just a random number.
   */
  public function getUniqueId($prepend = NULL): string|int {
    $id = rand(1, 1000000000);
    while (\in_array($id, $this->uniqueIds, TRUE)) {
      $id = rand(1, 1000000000);
    }
    $this->uniqueIds[] = $id;
    $return = ($prepend) ? "$prepend-$id" : $id;

    return $return;

  }

}
