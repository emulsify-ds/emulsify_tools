<?php

declare(strict_types=1);

namespace Consolidation\AnnotatedCommand;

/**
 * Minimal command-result fixture used by Drush command unit tests.
 */
final class CommandResult {

  /**
   * Creates the command result.
   */
  private function __construct(
    private readonly mixed $data,
    private readonly int $exitCode,
  ) {}

  /**
   * Creates a result with structured data and an explicit exit code.
   */
  public static function dataWithExitCode(mixed $data, int $exitCode): self {
    return new self($data, $exitCode);
  }

  /**
   * Returns structured command data.
   */
  public function getData(): mixed {
    return $this->data;
  }

  /**
   * Returns the command exit code.
   */
  public function getExitCode(): int {
    return $this->exitCode;
  }

}
