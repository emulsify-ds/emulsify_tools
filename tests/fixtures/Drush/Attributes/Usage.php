<?php

declare(strict_types=1);

namespace Drush\Attributes;

/**
 * Defines a Drush usage attribute fixture.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class Usage {

  /**
   * Creates the attribute.
   */
  public function __construct(
    public string $name,
    public string $description = '',
  ) {}

}
