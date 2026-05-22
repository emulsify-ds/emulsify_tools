<?php

declare(strict_types=1);

namespace Drush\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class Command {

  /**
   * Creates the attribute.
   */
  public function __construct(
    public string $name,
    public array $aliases = [],
  ) {}

}
