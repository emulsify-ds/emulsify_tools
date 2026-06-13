<?php

declare(strict_types=1);

namespace Drush\Attributes;

/**
 * Defines a Drush option attribute fixture.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class Option {

  /**
   * Creates the attribute.
   */
  public function __construct(
    public string $name,
    public string $description = '',
  ) {}

}
