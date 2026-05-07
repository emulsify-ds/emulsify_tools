<?php

declare(strict_types=1);

namespace Drupal\Tests\emulsify_tools\Unit;

use Drupal\emulsify_tools\SwitchExtension;
use Drupal\emulsify_tools\SwitchNode;
use Drupal\emulsify_tools\SwitchTokenParser;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Tests the custom Twig switch extension.
 */
#[CoversClass(SwitchExtension::class)]
#[CoversClass(SwitchNode::class)]
#[CoversClass(SwitchTokenParser::class)]
#[Group('emulsify_tools')]
final class SwitchExtensionTest extends UnitTestCase {

  /**
   * Tests that multi-value case expressions still render correctly.
   */
  public function testSwitchTagSupportsMultipleCaseValues(): void {
    $twig = new Environment(new ArrayLoader([
      'switch' => <<<'TWIG'
{% switch value %}
  {% case 'alpha' or 'beta' %}matched
  {% default %}default
{% endswitch %}
TWIG,
    ]));
    $twig->addExtension(new SwitchExtension());

    self::assertSame('matched', trim($twig->render('switch', ['value' => 'beta'])));
  }

  /**
   * Tests the default branch still renders when no case matches.
   */
  public function testSwitchTagFallsBackToDefault(): void {
    $twig = new Environment(new ArrayLoader([
      'switch' => <<<'TWIG'
{% switch value %}
  {% case 'alpha' or 'beta' %}matched
  {% default %}default
{% endswitch %}
TWIG,
    ]));
    $twig->addExtension(new SwitchExtension());

    self::assertSame('default', trim($twig->render('switch', ['value' => 'gamma'])));
  }

}
