<?php

declare(strict_types=1);

namespace Drupal\Tests\emulsify_tools\Unit;

use Drupal\Core\Template\Attribute;
use Drupal\emulsify_tools\BemTwigExtension;
use Drupal\emulsify_tools\TwigAttributeManager;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the BEM Twig extension.
 */
#[CoversClass(BemTwigExtension::class)]
#[Group('emulsify_tools')]
final class BemTwigExtensionTest extends UnitTestCase {

  /**
   * Tests positional bem() arguments.
   */
  public function testBemBuildsExpectedClassesFromPositionalArguments(): void {
    $extension = new BemTwigExtension(new TwigAttributeManager());
    $sourceAttributes = new Attribute([
      'class' => ['existing'],
      'data-role' => 'heading',
    ]);

    $result = $extension->bem(
      ['attributes' => $sourceAttributes],
      'title',
      ['small', 'red'],
      'card',
      ['js-click', 'bad value'],
    );

    $resultArray = $result->toArray();

    self::assertSame([
      'card__title',
      'card__title--small',
      'card__title--red',
      'js-click',
      'bad-value',
      'existing',
    ], $resultArray['class']);
    self::assertSame('heading', $resultArray['data-role']);
  }

  /**
   * Tests array-style bem() arguments.
   */
  public function testBemBuildsExpectedClassesFromConfigurationArray(): void {
    $extension = new BemTwigExtension(new TwigAttributeManager());

    $result = $extension->bem(
      [],
      [
        'base_class' => 'title',
        'modifiers' => 'small',
        'blockname' => 'card',
        'extra' => ['js-click'],
      ],
    );

    self::assertSame([
      'card__title',
      'card__title--small',
      'js-click',
    ], $result->toArray()['class']);
  }

}
