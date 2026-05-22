<?php

declare(strict_types=1);

namespace Drush\Attributes;

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
