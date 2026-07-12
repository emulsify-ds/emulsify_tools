<?php

declare(strict_types=1);

namespace Drupal\Tests\emulsify_tools\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\YamlFileLoader;
use Drupal\emulsify_tools\ThemeGeneration\DrupalStarterkitThemeGenerator;
use Drupal\emulsify_tools\ThemeGeneration\EmulsifyThemeGenerator;
use Drupal\emulsify_tools\ThemeGeneration\LegacyThemeGenerator;
use Drupal\emulsify_tools\ThemeGeneration\ThemeGeneratorInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Tag\TaggedValue;

/**
 * Tests theme generation service wiring.
 */
#[CoversNothing]
#[Group('emulsify_tools')]
final class ThemeGenerationServicesTest extends UnitTestCase {

  /**
   * Tests the generator interface resolves to the format-selecting service.
   */
  public function testThemeGeneratorServiceWiring(): void {
    $services = Yaml::decode($this->readFile(dirname(__DIR__, 3) . '/emulsify_tools.services.yml'));

    self::assertIsArray($services);
    self::assertSame(
      EmulsifyThemeGenerator::class,
      $services['services'][ThemeGeneratorInterface::class]['alias'],
    );
    self::assertSame(
      ['%app.root%'],
      $services['services'][DrupalStarterkitThemeGenerator::class]['arguments'],
    );

    $arguments = $services['services'][EmulsifyThemeGenerator::class]['arguments'];
    self::assertSame('@extension.list.theme', $arguments[0]);
    self::assertSame('@' . DrupalStarterkitThemeGenerator::class, $arguments[1]);
    self::assertInstanceOf(TaggedValue::class, $arguments[2]);
    self::assertSame('service_closure', $arguments[2]->getTag());
    self::assertSame('@' . LegacyThemeGenerator::class, $arguments[2]->getValue());
    self::assertSame('%app.root%', $arguments[3]);
  }

  /**
   * Tests legacy service definitions use Drupal deprecation metadata.
   */
  public function testLegacyServiceDeprecationMetadata(): void {
    $serviceFile = dirname(__DIR__, 3) . '/emulsify_tools.services.yml';
    $container = new ContainerBuilder();
    (new YamlFileLoader($container))->load($serviceFile);

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
   * Reads a fixture file.
   */
  private function readFile(string $path): string {
    $contents = file_get_contents($path);
    if ($contents === FALSE) {
      throw new \RuntimeException(sprintf('Failed to read file "%s".', $path));
    }

    return $contents;
  }

}
