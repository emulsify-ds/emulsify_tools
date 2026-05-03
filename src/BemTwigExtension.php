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
   * @param array|object|string $baseClass
   *   A base class string or a configuration object/array.
   * @param array|string $modifiers
   *   Optional BEM modifiers.
   * @param string $blockname
   *   Optional BEM block name.
   * @param array|string $extra
   *   Optional extra classes.
   *
   * @return \Drupal\Core\Template\Attribute
   *   A detached attribute collection.
   */
  public function bem(
    array $context,
    array|object|string $baseClass,
    array|string $modifiers = [],
    string $blockname = '',
    array|string $extra = [],
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
   * @param array|object|string $baseClass
   *   A base class string or a configuration object/array.
   * @param array|string $modifiers
   *   Optional BEM modifiers.
   * @param string $blockName
   *   Optional BEM block name.
   * @param array|string $extra
   *   Optional extra classes.
   *
   * @return array
   *   The resolved arguments in base, modifiers, blockname, extra order.
   */
  private function resolveArguments(
    array|object|string $baseClass,
    array|string $modifiers,
    string $blockName,
    array|string $extra,
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
      trim($baseClass),
      $this->normalizeStringList($modifiers),
      trim($blockName),
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
   * @param array|string $values
   *   The incoming values.
   *
   * @return string[]
   *   The normalized list.
   */
  private function normalizeStringList(array|string $values): array {
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

}
