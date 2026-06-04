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
  public function testMergeContextAttributesDetachesAndMergesValues(): void {
    $sourceAttributes = new Attribute([
      'class' => ['existing', 'existing'],
      'data-role' => 'banner',
    ]);

    $manager = new TwigAttributeManager();
    $result = $manager->mergeContextAttributes(
      ['attributes' => $sourceAttributes],
      [
        'class' => ['new class', 'existing'],
        'title' => 'Hero',
        'data-values' => ['first', 'second'],
      ],
    );

    $resultArray = $result->toArray();

    self::assertSame(['existing', 'new-class'], $resultArray['class']);
    self::assertSame('banner', $resultArray['data-role']);
    self::assertSame('Hero', $resultArray['title']);
    self::assertSame(['first', 'second'], $resultArray['data-values']);
    self::assertSame([], $sourceAttributes->toArray());
  }

  /**
   * Tests BEM attribute merging preserves generated class precedence.
   */
  public function testBuildBemAttributesMergesAndSanitizesClasses(): void {
    $sourceAttributes = new Attribute([
      'class' => ['existing', 'bad value'],
      'data-role' => 'banner',
    ]);

    $manager = new TwigAttributeManager();
    $result = $manager->buildBemAttributes(
      ['attributes' => $sourceAttributes],
      ['card__title', 'card__title--featured', 'js hook'],
    );

    $resultArray = $result->toArray();

    self::assertSame([
      'card__title',
      'card__title--featured',
      'js-hook',
      'existing',
      'bad-value',
    ], $resultArray['class']);
    self::assertSame('banner', $resultArray['data-role']);
    self::assertSame([], $sourceAttributes->toArray());
  }

}
