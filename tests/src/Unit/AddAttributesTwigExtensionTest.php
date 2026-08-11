<?php

declare(strict_types=1);

namespace Drupal\Tests\emulsify_tools\Unit;

use Drupal\Core\Template\Attribute;
use Drupal\emulsify_tools\AddAttributesTwigExtension;
use Drupal\emulsify_tools\TwigAttributeManager;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the add_attributes Twig extension.
 */
#[CoversClass(AddAttributesTwigExtension::class)]
#[Group('emulsify_tools')]
final class AddAttributesTwigExtensionTest extends UnitTestCase {

  /**
   * Tests Tailwind classes survive context detachment and merging unchanged.
   */
  public function testAddAttributesPreservesTailwindClasses(): void {
    $extension = new AddAttributesTwigExtension(new TwigAttributeManager());
    $sourceAttributes = new Attribute([
      'class' => ['sm:hover:text-white'],
    ]);

    $result = $extension->addAttributes(
      ['attributes' => $sourceAttributes],
      [
        'class' => [
          'w-1/2',
          '!mt-0',
          'grid-cols-[1fr_minmax(0,2fr)]',
        ],
      ],
    );

    self::assertSame([
      'sm:hover:text-white',
      'w-1/2',
      '!mt-0',
      'grid-cols-[1fr_minmax(0,2fr)]',
    ], $result->toArray()['class']);
    self::assertSame([], $sourceAttributes->toArray());
  }

}
