<?php

declare(strict_types=1);

namespace Drupal\Tests\emulsify_tools\Unit;

use Drupal\emulsify_tools\SwitchExtension;
use Drupal\emulsify_tools\SwitchNode;
use Drupal\emulsify_tools\SwitchTokenParser;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
   * Tests a matching case renders child output without falling through.
   */
  #[DataProvider('renderingModeProvider')]
  public function testSwitchTagRendersMatchingCase(bool $useYield): void {
    $twig = new Environment(new ArrayLoader([
      'switch' => <<<'TWIG'
{% switch value %}
  {% case 'alpha' %}{{ label }}
  {% case 'beta' %}other
  {% default %}default
{% endswitch %}
TWIG,
    ]), ['use_yield' => $useYield]);
    $twig->addExtension(new SwitchExtension());

    self::assertSame('&lt;em&gt;matched&lt;/em&gt;', trim($twig->render('switch', [
      'value' => 'alpha',
      'label' => '<em>matched</em>',
    ])));
    self::assertSame('other', trim($twig->render('switch', ['value' => 'beta'])));
  }

  /**
   * Tests that multi-value case expressions still render correctly.
   */
  #[DataProvider('renderingModeProvider')]
  public function testSwitchTagSupportsMultipleCaseValues(bool $useYield): void {
    $twig = new Environment(new ArrayLoader([
      'switch' => <<<'TWIG'
{% switch value %}
  {% case 'alpha' or 'beta' %}matched
  {% default %}default
{% endswitch %}
TWIG,
    ]), ['use_yield' => $useYield]);
    $twig->addExtension(new SwitchExtension());

    self::assertSame('matched', trim($twig->render('switch', ['value' => 'alpha'])));
    self::assertSame('matched', trim($twig->render('switch', ['value' => 'beta'])));
  }

  /**
   * Tests the default branch still renders when no case matches.
   */
  #[DataProvider('renderingModeProvider')]
  public function testSwitchTagFallsBackToDefault(bool $useYield): void {
    $twig = new Environment(new ArrayLoader([
      'switch' => <<<'TWIG'
{% switch value %}
  {% case 'alpha' or 'beta' %}matched
  {% default %}default
{% endswitch %}
TWIG,
    ]), ['use_yield' => $useYield]);
    $twig->addExtension(new SwitchExtension());

    self::assertSame('default', trim($twig->render('switch', ['value' => 'gamma'])));
  }

  /**
   * Tests an unmatched switch without a default produces no output.
   */
  #[DataProvider('renderingModeProvider')]
  public function testSwitchTagWithoutMatchingCaseOrDefault(bool $useYield): void {
    $twig = new Environment(new ArrayLoader([
      'switch' => <<<'TWIG'
{% switch value %}
  {% case 'alpha' %}matched
{% endswitch %}
TWIG,
    ]), ['use_yield' => $useYield]);
    $twig->addExtension(new SwitchExtension());

    self::assertSame('', $twig->render('switch', ['value' => 'gamma']));
  }

  /**
   * Tests variables assigned in case and default branches remain available.
   */
  #[DataProvider('renderingModeProvider')]
  public function testSwitchTagAssignsVariablesInBranches(bool $useYield): void {
    $twig = new Environment(new ArrayLoader([
      'switch' => <<<'TWIG'
{%- switch value -%}
  {%- case 'news' -%}
    {%- set layout = 'stacked' -%}
    {%- set modifiers = ['offset-image'] -%}
  {%- default -%}
    {%- set layout = 'inline' -%}
    {%- set modifiers = [] -%}
{%- endswitch -%}
{{ layout }}|{{ modifiers|join(',') }}
TWIG,
    ]), ['use_yield' => $useYield, 'strict_variables' => TRUE]);
    $twig->addExtension(new SwitchExtension());

    self::assertSame('stacked|offset-image', $twig->render('switch', ['value' => 'news']));
    self::assertSame('inline|', $twig->render('switch', ['value' => 'page']));
  }

  /**
   * Provides both supported Twig rendering modes.
   *
   * @return array<string, array{bool}>
   *   The use_yield option for each rendering mode.
   */
  public static function renderingModeProvider(): array {
    return [
      'use_yield disabled' => [FALSE],
      'use_yield enabled' => [TRUE],
    ];
  }

}
