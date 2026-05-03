<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools;

use Drupal\Core\Template\Attribute;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Adds a Twig helper for merging detached attribute collections.
 */
final class AddAttributesTwigExtension extends AbstractExtension {

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
        'add_attributes',
        [$this, 'addAttributes'],
        ['needs_context' => TRUE, 'is_safe' => ['html']],
      ),
    ];
  }

  /**
   * Merges additional attributes into the current Twig attributes.
   *
   * @param array $context
   *   The Twig render context.
   * @param array $additionalAttributes
   *   Additional attributes to merge into the current context.
   *
   * @return \Drupal\Core\Template\Attribute
   *   A detached merged attribute collection.
   */
  public function addAttributes(array $context, array $additionalAttributes = []): Attribute {
    return $this->attributeManager->mergeContextAttributes($context, $additionalAttributes);
  }

}
