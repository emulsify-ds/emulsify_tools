<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools;

use Drupal\Component\Utility\Html;
use Drupal\Core\Template\Attribute;

/**
 * Normalizes and merges Twig attributes for Emulsify extensions.
 */
final class TwigAttributeManager {

  /**
   * Builds the final attribute collection for add_attributes().
   *
   * @param array<string, mixed> $context
   *   The Twig render context.
   * @param mixed $additionalAttributes
   *   Additional attributes to merge onto the context attributes.
   *
   * @return \Drupal\Core\Template\Attribute
   *   The merged attribute collection.
   */
  public function mergeContextAttributes(array $context, mixed $additionalAttributes = []): Attribute {
    $attributes = $this->consumeContextAttributes($context);

    foreach ($this->normalizeAttributeMap($additionalAttributes) as $name => $value) {
      $this->mergeAttribute($attributes, (string) $name, $value);
    }

    return $attributes;
  }

  /**
   * Builds the final attribute collection for bem().
   *
   * @param array<string, mixed> $context
   *   The Twig render context.
   * @param string[] $classes
   *   The classes to attach to the attribute collection.
   *
   * @return \Drupal\Core\Template\Attribute
   *   The merged attribute collection.
   */
  public function buildBemAttributes(array $context, array $classes): Attribute {
    $attributes = $this->consumeContextAttributes($context);

    if ($classes !== []) {
      $existingClassValue = $attributes->offsetExists('class')
        ? $attributes->offsetGet('class')?->value()
        : NULL;
      $existingClasses = $existingClassValue !== NULL
        ? (array) $this->normalizeAttributeValue('class', $existingClassValue)
        : [];
      $attributes->setAttribute('class', $this->sanitizeClasses(array_merge($classes, $existingClasses)));
    }

    return $attributes;
  }

  /**
   * Merges one attribute value onto an attribute collection.
   *
   * @param \Drupal\Core\Template\Attribute $attributes
   *   The target attribute collection.
   * @param string $name
   *   The attribute name.
   * @param mixed $value
   *   The attribute value.
   */
  public function mergeAttribute(Attribute $attributes, string $name, mixed $value): void {
    $normalizedValue = $this->normalizeAttributeValue($name, $value);
    if ($this->shouldSkipValue($normalizedValue)) {
      return;
    }

    if (!$attributes->offsetExists($name)) {
      $attributes->setAttribute($name, $normalizedValue);
      return;
    }

    $existingValue = $attributes->offsetGet($name)?->value();
    $attributes->setAttribute($name, $this->mergeAttributeValues($name, $existingValue, $normalizedValue));
  }

  /**
   * Normalizes arbitrary Twig input into an attribute-name map.
   *
   * @param mixed $attributes
   *   The incoming Twig value.
   *
   * @return array<string, mixed>
   *   A normalized attribute map.
   */
  private function normalizeAttributeMap(mixed $attributes): array {
    if ($attributes instanceof Attribute) {
      return $attributes->toArray();
    }

    if (is_array($attributes)) {
      return $attributes;
    }

    if (is_object($attributes)) {
      return (array) $attributes;
    }

    return [];
  }

  /**
   * Copies context attributes into a new collection and clears the source.
   *
   * @param array<string, mixed> $context
   *   The Twig render context.
   *
   * @return \Drupal\Core\Template\Attribute
   *   A detached attribute collection.
   */
  public function consumeContextAttributes(array $context): Attribute {
    $sourceAttributes = $this->normalizeContextAttributes($context);
    $attributes = new Attribute();

    foreach (array_keys($sourceAttributes->toArray()) as $name) {
      $attributes->setAttribute(
        $name,
        $this->normalizeAttributeValue($name, $sourceAttributes->offsetGet($name)?->value()),
      );
      $sourceAttributes->removeAttribute($name);
    }

    return $attributes;
  }

  /**
   * Returns a normalized Attribute object from Twig context.
   *
   * @param array<string, mixed> $context
   *   The Twig render context.
   *
   * @return \Drupal\Core\Template\Attribute
   *   The normalized attributes object.
   */
  private function normalizeContextAttributes(array $context): Attribute {
    $attributes = $context['attributes'] ?? new Attribute();

    if ($attributes instanceof Attribute) {
      return $attributes;
    }

    return new Attribute((array) $attributes);
  }

  /**
   * Normalizes arbitrary attribute values into Drupal Attribute values.
   *
   * @param string $name
   *   The attribute name.
   * @param mixed $value
   *   The incoming value.
   *
   * @return mixed
   *   The normalized attribute value.
   */
  private function normalizeAttributeValue(string $name, mixed $value): mixed {
    if ($value instanceof Attribute) {
      $attributeValues = $value->toArray();
      return $this->normalizeAttributeValue($name, $attributeValues[$name] ?? []);
    }

    if (is_array($value)) {
      return $this->normalizeArrayValue($name, $value);
    }

    if ($value === NULL) {
      return $name === 'class' ? [] : NULL;
    }

    if (is_bool($value)) {
      return $name === 'class' ? [$value ? 'true' : 'false'] : $value;
    }

    if (is_scalar($value)) {
      return $name === 'class' ? [(string) $value] : $value;
    }

    if ($value instanceof \Stringable) {
      return $name === 'class' ? [(string) $value] : (string) $value;
    }

    return $name === 'class' ? [] : NULL;
  }

  /**
   * Normalizes array attribute values.
   *
   * @param string $name
   *   The attribute name.
   * @param mixed[] $values
   *   The incoming values.
   *
   * @return mixed[]
   *   The normalized attribute values.
   */
  private function normalizeArrayValue(string $name, array $values): array {
    $normalizedValues = [];

    foreach ($values as $value) {
      $normalizedValue = $this->normalizeAttributeValue($name, $value);
      if ($this->shouldSkipValue($normalizedValue)) {
        continue;
      }

      if (is_array($normalizedValue)) {
        array_push($normalizedValues, ...$normalizedValue);
        continue;
      }

      $normalizedValues[] = $normalizedValue;
    }

    if ($name === 'class') {
      return $this->sanitizeClasses($normalizedValues);
    }

    return $normalizedValues;
  }

  /**
   * Merges two normalized attribute values together.
   *
   * @param string $name
   *   The attribute name.
   * @param mixed $existingValue
   *   The existing attribute value.
   * @param mixed $incomingValue
   *   The incoming attribute value.
   *
   * @return mixed
   *   The merged attribute value.
   */
  private function mergeAttributeValues(string $name, mixed $existingValue, mixed $incomingValue): mixed {
    $normalizedExistingValue = $this->normalizeAttributeValue($name, $existingValue);

    if ($name === 'class') {
      return $this->sanitizeClasses(array_merge((array) $normalizedExistingValue, (array) $incomingValue));
    }

    if (is_array($normalizedExistingValue) || is_array($incomingValue)) {
      return array_values(array_merge((array) $normalizedExistingValue, (array) $incomingValue));
    }

    return $incomingValue;
  }

  /**
   * Returns whether a normalized value should be ignored.
   *
   * @param mixed $value
   *   The value to inspect.
   *
   * @return bool
   *   TRUE when the value should be skipped.
   */
  private function shouldSkipValue(mixed $value): bool {
    return $value === NULL || $value === '' || $value === [];
  }

  /**
   * Sanitizes CSS classes.
   *
   * @param mixed[] $classes
   *   The classes to sanitize.
   *
   * @return string[]
   *   The sanitized class list.
   */
  private function sanitizeClasses(array $classes): array {
    $sanitizedClasses = array_map(
      static fn (mixed $class): string => Html::cleanCssIdentifier((string) $class),
      $classes,
    );

    return array_values(array_unique(array_filter(
      $sanitizedClasses,
      static fn (string $class): bool => $class !== '',
    )));
  }

}
