<?php

declare(strict_types=1);

namespace Drupal\Tests\emulsify_tools\Integration;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\YamlFileLoader;
use Drupal\emulsify_tools\ThemeGeneration\DrupalStarterkitThemeGenerator;
use Drupal\emulsify_tools\ThemeGeneration\EmulsifyThemeGenerator;
use Drupal\emulsify_tools\ThemeGeneration\LegacyThemeGenerator;
use Drupal\emulsify_tools\ThemeGeneration\ThemeGeneratorInterface;
use Drupal\emulsify_tools\ThemeGeneration\ThemeMachineNameFactory;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests theme generation service wiring.
 */
#[CoversNothing]
#[Group('emulsify_tools')]
final class ThemeGenerationServicesTest extends UnitTestCase {

  /**
   * Tests the public generator alias and strategy services are defined.
   */
  public function testThemeGeneratorServiceAlias(): void {
    $container = $this->loadContainer();

    self::assertSame(
      EmulsifyThemeGenerator::class,
      (string) $container->getAlias(ThemeGeneratorInterface::class),
    );
    self::assertTrue($container->hasDefinition(EmulsifyThemeGenerator::class));
    self::assertTrue($container->hasDefinition(DrupalStarterkitThemeGenerator::class));
    self::assertTrue($container->hasDefinition(LegacyThemeGenerator::class));
    self::assertTrue($container->hasDefinition(ThemeMachineNameFactory::class));
  }

  /**
   * Tests legacy service definitions use Drupal deprecation metadata.
   */
  public function testLegacyServiceDeprecationMetadata(): void {
    $container = $this->loadContainer();

    foreach ([
      'emulsify_tools.subtheme_generator',
      LegacyThemeGenerator::class,
      'Drupal\\emulsify_tools\\Archive\\StarterRecipeArchiveExtractor',
    ] as $serviceId) {
      $definition = $container->getDefinition($serviceId);
      self::assertTrue($definition->isDeprecated());
      $deprecation = $definition->getDeprecation($serviceId);
      self::assertStringContainsString('deprecated in emulsify_tools:2.2.0', $deprecation['message']);
      self::assertStringContainsString('removed from emulsify_tools:3.0.0', $deprecation['message']);
      self::assertStringContainsString(ThemeGeneratorInterface::class, $deprecation['message']);
    }
  }

  /**
   * Loads the module service definitions into a test container.
   */
  private function loadContainer(): ContainerBuilder {
    $container = new ContainerBuilder();
    (new YamlFileLoader($container))->load(dirname(__DIR__, 3) . '/emulsify_tools.services.yml');

    return $container;
  }

}
