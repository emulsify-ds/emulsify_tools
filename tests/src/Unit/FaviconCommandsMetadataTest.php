<?php

declare(strict_types=1);

namespace Drupal\Tests\emulsify_tools\Unit;

use Consolidation\AnnotatedCommand\CommandResult;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Extension\ThemeSettingsProvider;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\emulsify\Favicon\FaviconThemeManager as ThemeManagerFixture;
use Drupal\emulsify_tools\Drush\Commands\FaviconCommands;
use Drupal\emulsify_tools\Favicon\FaviconCommandManager;
use Drupal\Tests\UnitTestCase;
use Drush\Attributes\Command;
use Drush\Attributes\Help;
use Drush\Attributes\Option;
use Drush\Attributes\Usage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\AbstractLogger;

/**
 * Tests Drush metadata for Emulsify favicon package commands.
 */
#[CoversClass(FaviconCommands::class)]
#[Group('emulsify_tools')]
final class FaviconCommandsMetadataTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    ThemeManagerFixture::resetFixture();
    parent::tearDown();
  }

  /**
   * Tests command names, help text, and documented usage examples.
   */
  public function testFaviconCommandMetadata(): void {
    $expected = [
      'generate' => [
        'name' => 'emulsify_tools:favicon-generate',
        'description' => 'Generate or refresh a favicon package from Emulsify Drupal theme settings.',
        'example' => 'emulsify_tools:favicon-generate my_theme',
      ],
      'status' => [
        'name' => 'emulsify_tools:favicon-status',
        'description' => 'Check favicon package, dependency, and portable source status for an Emulsify-based theme.',
        'example' => 'emulsify_tools:favicon-status my_theme',
      ],
      'reset' => [
        'name' => 'emulsify_tools:favicon-reset',
        'description' => 'Remove generated favicon package state and restore default favicon behavior for an Emulsify-based theme.',
        'example' => 'emulsify_tools:favicon-reset my_theme',
      ],
    ];

    $reflection = new \ReflectionClass(FaviconCommands::class);
    foreach ($expected as $method => $metadata) {
      $reflection_method = $reflection->getMethod($method);

      $command = $this->getSingleAttribute($reflection_method, Command::class);
      self::assertSame($metadata['name'], $command->name);

      $help = $this->getSingleAttribute($reflection_method, Help::class);
      self::assertSame($metadata['description'], $help->description);
      self::assertNotEmpty($help->synopsis);

      $usage_examples = array_map(
        static fn (Usage $usage): string => $usage->name,
        $this->getAttributes($reflection_method, Usage::class),
      );
      self::assertContains($metadata['example'], $usage_examples);
    }
  }

  /**
   * Tests favicon command option metadata.
   */
  public function testFaviconCommandOptionMetadata(): void {
    $reflection = new \ReflectionClass(FaviconCommands::class);
    $expectedOptions = [
      'generate' => ['all'],
      'status' => ['all', 'format'],
      'reset' => ['all'],
    ];

    foreach ($expectedOptions as $method => $optionNames) {
      $reflectionMethod = $reflection->getMethod($method);
      $options = array_map(
        static fn (Option $option): string => $option->name,
        $this->getAttributes($reflectionMethod, Option::class),
      );

      foreach ($optionNames as $optionName) {
        self::assertContains($optionName, $options);
      }
    }
  }

  /**
   * Tests status returns structured rows when a format is requested.
   */
  public function testStatusReturnsStructuredRowsForFormattedOutput(): void {
    $this->setStatusFixtures();
    $commands = $this->createCommands();

    $result = $commands->status('sfasu', ['format' => 'json']);

    self::assertInstanceOf(RowsOfFields::class, $result);
    $rows = $result->getArrayCopy();
    self::assertCount(1, $rows);
    self::assertSame([
      'theme' => 'sfasu',
      'package_enabled' => TRUE,
      'package_state' => 'missing',
      'gd' => TRUE,
      'imagick' => FALSE,
      'package_exists' => FALSE,
      'portable_source_available' => TRUE,
      'portable_source_size' => 128,
      'hash' => 'abc123',
      'path' => 'public://favicon-package/sfasu/abc123',
      'generated_at' => 1700000000,
      'warnings' => [
        'Portable source contains embedded raster data.',
        'Status error: Package missing from public files.',
      ],
    ], $rows[0]);
  }

  /**
   * Tests --all status aggregates rows and preserves non-zero errors.
   */
  public function testStatusAllAggregatesStructuredRowsAndErrors(): void {
    $this->setStatusFixtures();
    ThemeManagerFixture::$exceptions['loadThemeSettings']['broken'] = new \RuntimeException('Unable to load settings.');
    $commands = $this->createCommands([
      'emulsify' => $this->createTheme(),
      'sfasu' => $this->createTheme(['emulsify' => 'emulsify']),
      'broken' => $this->createTheme(['emulsify' => 'emulsify']),
      'olivero' => $this->createTheme(),
    ]);

    $result = $commands->status(NULL, ['all' => TRUE, 'format' => 'json']);

    self::assertInstanceOf(CommandResult::class, $result);
    self::assertSame(1, $result->getExitCode());
    $data = $result->getData();
    self::assertInstanceOf(RowsOfFields::class, $data);
    $rows = $data->getArrayCopy();
    self::assertSame(['emulsify', 'sfasu', 'broken'], array_column($rows, 'theme'));
    self::assertSame('error', $rows[2]['package_state']);
    self::assertSame(['Unable to load settings.'], $rows[2]['warnings']);
  }

  /**
   * Tests --all generation continues and returns non-zero when a theme errors.
   */
  public function testGenerateAllReturnsNonZeroWhenAnyThemeErrors(): void {
    ThemeManagerFixture::$settings = ['favicon_package_enabled' => TRUE];
    ThemeManagerFixture::$generateResult = [
      'generated' => TRUE,
      'settings' => [
        'favicon_package_hash' => 'abc123',
      ],
      'result' => [
        'hash' => 'abc123',
        'path' => 'public://favicon-package/sfasu/abc123',
      ],
    ];
    ThemeManagerFixture::$exceptions['generatePackage']['broken'] = new \RuntimeException('Generate failed.');

    $logger = new RecordingLogger();
    $commands = $this->createCommands([
      'emulsify' => $this->createTheme(),
      'sfasu' => $this->createTheme(['emulsify' => 'emulsify']),
      'broken' => $this->createTheme(['emulsify' => 'emulsify']),
    ]);
    $commands->setLogger($logger);

    self::assertSame(1, $commands->generate(NULL, ['all' => TRUE]));
    self::assertTrue($logger->hasErrorContaining('Generate failed.'));
    self::assertSame([
      'emulsify',
      'sfasu',
      'broken',
    ], array_values(array_filter(
      array_map(
        static fn (array $call): ?string => $call['method'] === 'generatePackage' ? $call['theme'] : NULL,
        ThemeManagerFixture::$calls,
      ),
    )));
  }

  /**
   * Tests --all reset continues and returns non-zero when a theme errors.
   */
  public function testResetAllReturnsNonZeroWhenAnyThemeErrors(): void {
    ThemeManagerFixture::$resetSettings = ['favicon_package_enabled' => FALSE];
    ThemeManagerFixture::$exceptions['resetThemeSettings']['broken'] = new \RuntimeException('Reset failed.');

    $logger = new RecordingLogger();
    $commands = $this->createCommands([
      'emulsify' => $this->createTheme(),
      'sfasu' => $this->createTheme(['emulsify' => 'emulsify']),
      'broken' => $this->createTheme(['emulsify' => 'emulsify']),
    ]);
    $commands->setLogger($logger);

    self::assertSame(1, $commands->reset(NULL, ['all' => TRUE]));
    self::assertTrue($logger->hasErrorContaining('Reset failed.'));
    self::assertSame([
      'emulsify',
      'sfasu',
      'broken',
    ], array_values(array_filter(
      array_map(
        static fn (array $call): ?string => $call['method'] === 'resetThemeSettings' ? $call['theme'] : NULL,
        ThemeManagerFixture::$calls,
      ),
    )));
  }

  /**
   * Returns one instantiated method attribute.
   *
   * @param \ReflectionMethod $method
   *   The reflected method.
   * @param class-string $attribute_class
   *   The attribute class name.
   */
  private function getSingleAttribute(\ReflectionMethod $method, string $attribute_class): object {
    $attributes = $this->getAttributes($method, $attribute_class);
    self::assertCount(1, $attributes);
    return $attributes[0];
  }

  /**
   * Returns instantiated method attributes.
   *
   * @param \ReflectionMethod $method
   *   The reflected method.
   * @param class-string $attribute_class
   *   The attribute class name.
   *
   * @return object[]
   *   The instantiated attributes.
   */
  private function getAttributes(\ReflectionMethod $method, string $attribute_class): array {
    return array_map(
      static fn (\ReflectionAttribute $attribute): object => $attribute->newInstance(),
      $method->getAttributes($attribute_class),
    );
  }

  /**
   * Sets package status fixtures.
   */
  private function setStatusFixtures(): void {
    ThemeManagerFixture::$settings = [
      'favicon_package_enabled' => TRUE,
    ];
    ThemeManagerFixture::$packageStatus = [
      'state' => 'missing',
      'hash' => 'abc123',
      'path' => 'public://favicon-package/sfasu/abc123',
      'package_exists' => FALSE,
      'portable_source_available' => TRUE,
      'portable_source_size' => 128,
      'analysis_warnings' => ['Portable source contains embedded raster data.'],
      'generated_at' => 1700000000,
      'error' => 'Package missing from public files.',
    ];
    ThemeManagerFixture::$runtimeDependencyStatus = [
      'gd' => TRUE,
      'imagick' => FALSE,
    ];
  }

  /**
   * Creates the command under test.
   *
   * @param array<string, object>|null $themes
   *   Available themes.
   * @param string $defaultTheme
   *   The configured default theme.
   */
  private function createCommands(?array $themes = NULL, string $defaultTheme = 'sfasu'): FaviconCommands {
    $commands = new FaviconCommands($this->createCommandManager(
      $defaultTheme,
      $themes ?? [
        'emulsify' => $this->createTheme(),
        'sfasu' => $this->createTheme(['emulsify' => 'emulsify']),
      ],
    ));
    $commands->setLogger(new RecordingLogger());

    return $commands;
  }

  /**
   * Creates the command manager under test.
   *
   * @param string $defaultTheme
   *   The configured default theme.
   * @param array<string, object> $themes
   *   Themes available in the current codebase.
   */
  private function createCommandManager(string $defaultTheme, array $themes): FaviconCommandManager {
    $themeExtensionList = $this->createMock(ThemeExtensionList::class);
    $themeExtensionList->method('getList')->willReturn($themes);

    $systemThemeConfig = $this->createMock(ImmutableConfig::class);
    $systemThemeConfig->method('get')
      ->with('default')
      ->willReturn($defaultTheme);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('system.theme')
      ->willReturn($systemThemeConfig);

    return new FaviconCommandManager(
      $themeExtensionList,
      $configFactory,
      $this->createMock(ThemeSettingsProvider::class),
      $this->createMock(FileSystemInterface::class),
      $this->createMock(FileUrlGeneratorInterface::class),
      $this->createMock(CacheTagsInvalidatorInterface::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(LockBackendInterface::class),
    );
  }

  /**
   * Creates a theme extension fixture.
   *
   * @param array<string, string> $baseThemes
   *   Base theme map.
   */
  private function createTheme(array $baseThemes = []): object {
    return (object) [
      'info' => ['name' => 'Test theme'],
      'base_themes' => $baseThemes,
    ];
  }

}

/**
 * Records command log messages for assertions.
 */
final class RecordingLogger extends AbstractLogger {

  /**
   * Recorded messages.
   *
   * @var list<array{level: mixed, message: string}>
   */
  private array $records = [];

  /**
   * {@inheritdoc}
   */
  public function log($level, \Stringable|string $message, array $context = []): void {
    $this->records[] = [
      'level' => $level,
      'message' => (string) $message,
    ];
  }

  /**
   * Returns whether an error message contains the given fragment.
   */
  public function hasErrorContaining(string $fragment): bool {
    foreach ($this->records as $record) {
      if ($record['level'] === 'error' && str_contains($record['message'], $fragment)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
