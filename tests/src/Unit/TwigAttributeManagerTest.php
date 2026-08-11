<?php

declare(strict_types=1);

namespace Drupal\Tests\emulsify_tools\Unit;

use Drupal\Core\Template\Attribute;
use Drupal\emulsify_tools\TwigAttributeManager;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests attribute normalization for Emulsify Twig helpers.
 */
#[CoversClass(TwigAttributeManager::class)]
#[Group('emulsify_tools')]
final class TwigAttributeManagerTest extends UnitTestCase {

  /**
   * Tests add_attributes()-style merging and attribute detachment.
   */
  public function testMergeContextAttributesPreservesClassNamesAndNormalizesValues(): void {
    $sourceAttributes = new Attribute([
      'class' => ['md:hover:bg-blue-500', 'md:hover:bg-blue-500', ''],
      'data-role' => 'banner',
    ]);

    $manager = new TwigAttributeManager();
    $result = $manager->mergeContextAttributes(
      ['attributes' => $sourceAttributes],
      [
        'class' => [
          'w-1/2',
          '!mt-0',
          '[mask-type:luminance]',
          '',
          NULL,
          7,
          'md:hover:bg-blue-500',
        ],
        'title' => 'Hero',
        'data-values' => ['first', 'second'],
      ],
    );

    $resultArray = $result->toArray();

    self::assertSame([
      'md:hover:bg-blue-500',
      'w-1/2',
      '!mt-0',
      '[mask-type:luminance]',
      '7',
    ], $resultArray['class']);
    self::assertSame('banner', $resultArray['data-role']);
    self::assertSame('Hero', $resultArray['title']);
    self::assertSame(['first', 'second'], $resultArray['data-values']);
    self::assertSame([], $sourceAttributes->toArray());
  }

  /**
   * Tests BEM attribute merging preserves generated class precedence.
   */
  public function testBuildBemAttributesMergesAndNormalizesClasses(): void {
    $sourceAttributes = new Attribute([
      'class' => ['dark:lg:hover:text-white', 'dark:lg:hover:text-white'],
      'data-role' => 'banner',
    ]);

    $manager = new TwigAttributeManager();
    $result = $manager->buildBemAttributes(
      ['attributes' => $sourceAttributes],
      ['card__title', 'card__title--featured', '[&>*]:underline'],
    );

    $resultArray = $result->toArray();

    self::assertSame([
      'card__title',
      'card__title--featured',
      '[&>*]:underline',
      'dark:lg:hover:text-white',
    ], $resultArray['class']);
    self::assertSame('banner', $resultArray['data-role']);
    self::assertSame([], $sourceAttributes->toArray());
  }

  /**
   * Tests Drupal escapes class values when rendering the attribute object.
   */
  public function testRenderedAttributesEscapeClassValues(): void {
    $manager = new TwigAttributeManager();
    $result = $manager->mergeContextAttributes(
      [],
      [
        'class' => [
          'hover:bg-red-500',
          'x" onmouseover="alert(1)',
          '<script>',
        ],
      ],
    );

    self::assertSame(
      ' class="hover:bg-red-500 x&quot; onmouseover=&quot;alert(1) &lt;script&gt;"',
      (string) $result,
    );
  }

}
