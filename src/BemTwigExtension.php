<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools;

use Drupal\Core\Template\Attribute;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Adds a Twig helper for generating BEM-aware attribute objects.
 */
final class BemTwigExtension extends AbstractExtension {

  /**
   * Creates the extension.
   *
   * @param \Drupal\emulsify_tools\TwigAttributeManager $attributeManager
   *   The shared Twig attribute manager.
   */
  public function __construct(
    private readonly TwigAttributeManager $attributeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFunctions(): array {
    return [
      new TwigFunction(
        'bem',
        [$this, 'bem'],
        ['needs_context' => TRUE, 'is_safe' => ['html']],
      ),
    ];
  }

  /**
   * Builds a Drupal attribute object populated with BEM classes.
   *
   * @param array $context
   *   The Twig render context.
   * @param mixed $baseClass
   *   A base class string or a configuration object/array.
   * @param mixed $modifiers
   *   Optional BEM modifiers.
   * @param mixed $blockname
   *   Optional BEM block name.
   * @param mixed $extra
   *   Optional extra classes.
   *
   * @return \Drupal\Core\Template\Attribute
   *   A detached attribute collection.
   */
  public function bem(
    array $context,
    mixed $baseClass,
    mixed $modifiers = [],
    mixed $blockname = '',
    mixed $extra = [],
  ): Attribute {
    [$resolvedBaseClass, $resolvedModifiers, $resolvedBlockName, $resolvedExtra] = $this->resolveArguments(
      $baseClass,
      $modifiers,
      $blockname,
      $extra,
    );

    return $this->attributeManager->buildBemAttributes(
      $context,
      $this->buildClasses($resolvedBaseClass, $resolvedModifiers, $resolvedBlockName, $resolvedExtra),
    );
  }

  /**
   * Resolves positional or object-style bem() arguments.
   *
   * @param mixed $baseClass
   *   A base class string or a configuration object/array.
   * @param mixed $modifiers
   *   Optional BEM modifiers.
   * @param mixed $blockName
   *   Optional BEM block name.
   * @param mixed $extra
   *   Optional extra classes.
   *
   * @return array
   *   The resolved arguments in base, modifiers, blockname, extra order.
   */
  private function resolveArguments(
    mixed $baseClass,
    mixed $modifiers,
    mixed $blockName,
    mixed $extra,
  ): array {
    if (is_array($baseClass) || is_object($baseClass)) {
      $configuration = (array) $baseClass;

      return [
        (string) ($configuration['block'] ?? $configuration['base_class'] ?? ''),
        $this->normalizeStringList($configuration['modifiers'] ?? []),
        (string) ($configuration['element'] ?? $configuration['blockname'] ?? ''),
        $this->normalizeStringList($configuration['extra'] ?? []),
      ];
    }

    return [
      $this->normalizeString($baseClass),
      $this->normalizeStringList($modifiers),
      $this->normalizeString($blockName),
      $this->normalizeStringList($extra),
    ];
  }

  /**
   * Builds a BEM class list.
   *
   * @param string $baseClass
   *   The BEM base class.
   * @param string[] $modifiers
   *   BEM modifiers.
   * @param string $blockName
   *   Optional BEM block name.
   * @param string[] $extra
   *   Extra classes.
   *
   * @return string[]
   *   The generated class list.
   */
  private function buildClasses(string $baseClass, array $modifiers, string $blockName, array $extra): array {
    $classes = [];
    $bemBaseClass = $blockName !== '' && $baseClass !== '' ? $blockName . '__' . $baseClass : $baseClass;

    if ($bemBaseClass !== '') {
      $classes[] = $bemBaseClass;
      foreach ($modifiers as $modifier) {
        $classes[] = $bemBaseClass . '--' . $modifier;
      }
    }

    return array_merge($classes, $extra);
  }

  /**
   * Normalizes a class list into trimmed strings.
   *
   * @param mixed $values
   *   The incoming values.
   *
   * @return string[]
   *   The normalized list.
   */
  private function normalizeStringList(mixed $values): array {
    if (!is_array($values)) {
      $values = [$values];
    }

    $normalizedValues = [];
    foreach ($values as $value) {
      if (!is_scalar($value) && !$value instanceof \Stringable) {
        continue;
      }

      $stringValue = trim((string) $value);
      if ($stringValue === '') {
        continue;
      }

      $normalizedValues[] = $stringValue;
    }

    return $normalizedValues;
  }

  /**
   * Normalizes a possibly-null scalar-ish value into a trimmed string.
   */
  private function normalizeString(mixed $value): string {
    if (!is_scalar($value) && !$value instanceof \Stringable) {
      return '';
    }

    return trim((string) $value);
  }

}
