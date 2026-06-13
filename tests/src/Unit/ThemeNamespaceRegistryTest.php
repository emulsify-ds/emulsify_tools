<?php

declare(strict_types=1);

namespace Drupal\Tests\emulsify_tools\Unit;

use Drupal\emulsify_tools\Twig\ThemeNamespaceRegistry;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests Twig namespace template validation.
 */
#[CoversClass(ThemeNamespaceRegistry::class)]
#[Group('emulsify_tools')]
final class ThemeNamespaceRegistryTest extends UnitTestCase {

  /**
   * Tests supported and unsupported template names.
   *
   * @param string $templateName
   *   The template name to validate.
   * @param bool $expected
   *   The expected validation result.
   */
  #[DataProvider('providerTemplateNames')]
  public function testIsValidTemplateName(string $templateName, bool $expected): void {
    self::assertSame($expected, ThemeNamespaceRegistry::isValidTemplateName($templateName));
  }

  /**
   * Provides template names for validation tests.
   *
   * @return array<string, array{0: string, 1: bool}>
   *   The template name and expected result.
   */
  public static function providerTemplateNames(): array {
    return [
      'twig template' => ['@atoms/button.twig', TRUE],
      'uppercase extension' => ['@atoms/button.TWIG', TRUE],
      'html template' => ['@atoms/cards/card.html', TRUE],
      'svg template' => ['@icons/close.svg', TRUE],
      'missing namespace prefix' => ['atoms/button.twig', FALSE],
      'missing slash' => ['@atoms', FALSE],
      'missing namespace name' => ['@/button.twig', FALSE],
      'missing template path' => ['@atoms/', FALSE],
      'missing extension' => ['@atoms/button', FALSE],
      'unsupported extension' => ['@atoms/button.php', FALSE],
    ];
  }

}
