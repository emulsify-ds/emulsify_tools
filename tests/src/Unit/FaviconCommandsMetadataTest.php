<?php

declare(strict_types=1);

namespace Drupal\Tests\emulsify_tools\Unit;

use Drupal\emulsify_tools\Drush\Commands\FaviconCommands;
use Drupal\Tests\UnitTestCase;
use Drush\Attributes\Command;
use Drush\Attributes\Help;
use Drush\Attributes\Usage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests Drush metadata for Emulsify favicon package commands.
 */
#[CoversClass(FaviconCommands::class)]
#[Group('emulsify_tools')]
final class FaviconCommandsMetadataTest extends UnitTestCase {

  /**
   * Tests command names, help text, and documented usage examples.
   */
  public function testFaviconCommandMetadata(): void {
    $expected = [
      'generate' => [
        'name' => 'emulsify_tools:favicon-generate',
        'description' => 'Generate or refresh a favicon package from Emulsify Drupal theme settings.',
        'example' => 'emulsify_tools:favicon-generate my_theme',
      ],
      'status' => [
        'name' => 'emulsify_tools:favicon-status',
        'description' => 'Check favicon package, dependency, and portable source status for an Emulsify-based theme.',
        'example' => 'emulsify_tools:favicon-status my_theme',
      ],
      'reset' => [
        'name' => 'emulsify_tools:favicon-reset',
        'description' => 'Remove generated favicon package state and restore default favicon behavior for an Emulsify-based theme.',
        'example' => 'emulsify_tools:favicon-reset my_theme',
      ],
    ];

    $reflection = new \ReflectionClass(FaviconCommands::class);
    foreach ($expected as $method => $metadata) {
      $reflection_method = $reflection->getMethod($method);

      $command = $this->getSingleAttribute($reflection_method, Command::class);
      self::assertSame($metadata['name'], $command->name);

      $help = $this->getSingleAttribute($reflection_method, Help::class);
      self::assertSame($metadata['description'], $help->description);
      self::assertNotEmpty($help->synopsis);

      $usage_examples = array_map(
        static fn (Usage $usage): string => $usage->name,
        $this->getAttributes($reflection_method, Usage::class),
      );
      self::assertContains($metadata['example'], $usage_examples);
    }
  }

  /**
   * Returns one instantiated method attribute.
   *
   * @param \ReflectionMethod $method
   *   The reflected method.
   * @param class-string $attribute_class
   *   The attribute class name.
   */
  private function getSingleAttribute(\ReflectionMethod $method, string $attribute_class): object {
    $attributes = $this->getAttributes($method, $attribute_class);
    self::assertCount(1, $attributes);
    return $attributes[0];
  }

  /**
   * Returns instantiated method attributes.
   *
   * @param \ReflectionMethod $method
   *   The reflected method.
   * @param class-string $attribute_class
   *   The attribute class name.
   *
   * @return object[]
   *   The instantiated attributes.
   */
  private function getAttributes(\ReflectionMethod $method, string $attribute_class): array {
    return array_map(
      static fn (\ReflectionAttribute $attribute): object => $attribute->newInstance(),
      $method->getAttributes($attribute_class),
    );
  }

}
