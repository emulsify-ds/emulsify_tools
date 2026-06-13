<?php

declare(strict_types=1);

namespace Drush\Attributes;

/**
 * Defines a Drush argument attribute fixture.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class Argument {

  /**
   * Creates the attribute.
   */
  public function __construct(
    public string $name,
    public string $description = '',
  ) {}

}
