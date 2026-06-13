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
   * Logger used by the command fixture.
   */
  private ?LoggerInterface $logger = NULL;

  /**
   * Creates the command base class.
   */
  public function __construct() {}

  /**
   * Sets a logger for command tests.
   */
  public function setLogger(LoggerInterface $logger): void {
    $this->logger = $logger;
  }

  /**
   * Returns a logger compatible with Drush command code.
   */
  protected function logger(): LoggerInterface {
    return $this->logger ?? new NullLogger();
  }

}
