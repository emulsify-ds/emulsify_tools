<?php

declare(strict_types=1);

namespace Consolidation\OutputFormatters\StructuredData;

/**
 * Minimal rows-of-fields fixture used by Drush command unit tests.
 */
final class RowsOfFields {

  /**
   * Creates the rows object.
   *
   * @param list<array<string, mixed>> $rows
   *   Structured rows.
   */
  public function __construct(
    private readonly array $rows,
  ) {}

  /**
   * Returns the structured rows.
   *
   * @return list<array<string, mixed>>
   *   Structured rows.
   */
  public function getArrayCopy(): array {
    return $this->rows;
  }

}
