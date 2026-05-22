<?php

declare(strict_types=1);

namespace Drush\Commands;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Minimal Drush base command stub for static analysis in this test suite.
 */
abstract class DrushCommands {

  /**
   * Creates the command base class.
   */
  public function __construct() {}

  /**
   * Returns a logger compatible with Drush command code.
   */
  protected function logger(): LoggerInterface {
    return new NullLogger();
  }

}
