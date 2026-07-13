<?php

declare(strict_types=1);

namespace Drupal\Tests\emulsify_tools\Unit\ThemeGeneration;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Language\LanguageInterface;
use Drupal\emulsify_tools\ThemeGeneration\ThemeMachineNameFactory;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests theme machine-name normalization and validation.
 */
#[CoversClass(ThemeMachineNameFactory::class)]
#[Group('emulsify_tools')]
final class ThemeMachineNameFactoryTest extends UnitTestCase {

  /**
   * Tests successful normalization and preservation of the original input.
   */
  #[DataProvider('normalizationProvider')]
  public function testCreatesMachineName(
    string $input,
    string $transliterated,
    string $expected,
    bool $wasNormalized,
  ): void {
    $resolved = $this->createFactory($input, $transliterated)->create($input);

    self::assertSame($input, $resolved->originalInput);
    self::assertSame($expected, $resolved->machineName);
    self::assertSame($wasNormalized, $resolved->wasNormalized());
  }

  /**
   * Provides representative normalization scenarios.
   *
   * @return array<string, array{string, string, string, bool}>
   *   Input, transliteration result, machine name, and normalization flag.
   */
  public static function normalizationProvider(): array {
    return [
      'ordinary ASCII machine name' => ['my_theme', 'my_theme', 'my_theme', FALSE],
      'accented Latin text' => ['  Crème Brûlée  ', 'Creme Brulee', 'creme_brulee', TRUE],
      'multiple spaces and punctuation' => ['My  Theme: Project!', 'My  Theme: Project!', 'my_theme_project', TRUE],
      'repeated separators' => ['__My___Theme--Name__', '__My___Theme--Name__', 'my_theme_name', TRUE],
      'usable non-Latin transliteration' => ['東京', 'dongjing', 'dongjing', TRUE],
    ];
  }

  /**
   * Tests a name at Drupal's maximum extension-name length.
   */
  public function testAcceptsMaximumLengthBoundary(): void {
    $input = str_repeat('a', \DRUPAL_EXTENSION_NAME_MAX_LENGTH);

    self::assertSame($input, $this->createFactory($input, $input)->create($input)->machineName);
  }

  /**
   * Tests leading digits produce an actionable diagnostic.
   */
  public function testRejectsLeadingDigit(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('must start with a lowercase letter. Start the theme name with a letter, for example "my_theme".');

    $this->createFactory('123 Theme', '123 Theme')->create('123 Theme');
  }

  /**
   * Tests empty and whitespace-only input.
   */
  #[DataProvider('emptyInputProvider')]
  public function testRejectsEmptyInput(string $input): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('could not be converted to a valid Drupal machine name. Enter a name containing letters or numbers, for example "My Project Theme".');

    $this->createFactory($input, '')->create($input);
  }

  /**
   * Provides input without meaningful characters.
   *
   * @return array<string, array{string}>
   *   Empty input cases.
   */
  public static function emptyInputProvider(): array {
    return [
      'empty' => [''],
      'whitespace only' => [" \t\n "],
    ];
  }

  /**
   * Tests non-Latin input without a usable transliteration.
   */
  public function testRejectsUnusableNonLatinTransliteration(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('could not be converted to a valid Drupal machine name');

    $this->createFactory('😀', '_')->create('😀');
  }

  /**
   * Tests names over Drupal's extension-name length limit.
   */
  public function testRejectsNameOverMaximumLength(): void {
    $input = str_repeat('a', \DRUPAL_EXTENSION_NAME_MAX_LENGTH + 1);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage(sprintf(
      'must be %d characters or fewer. Choose a shorter name, for example "my_project_theme".',
      \DRUPAL_EXTENSION_NAME_MAX_LENGTH,
    ));

    $this->createFactory($input, $input)->create($input);
  }

  /**
   * Tests the Emulsify base theme name is reserved.
   */
  public function testRejectsReservedEmulsifyName(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('reserved by the Emulsify base theme. Choose a unique child theme name, for example "my_project_theme".');

    $this->createFactory('Emulsify', 'Emulsify')->create('Emulsify');
  }

  /**
   * Tests collisions with discovered themes.
   */
  public function testRejectsExistingThemeCollision(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('already used by an existing Drupal theme. Choose a unique child theme name, for example "my_project_theme".');

    $this->createFactory('Stark', 'Stark', ['stark'])->create('Stark');
  }

  /**
   * Creates a factory with deterministic collaborators.
   *
   * @param string $input
   *   Original input passed to the transliteration service.
   * @param string $transliterated
   *   Deterministic transliteration result.
   * @param string[] $existingThemes
   *   Existing theme machine names.
   */
  private function createFactory(
    string $input,
    string $transliterated,
    array $existingThemes = [],
  ): ThemeMachineNameFactory {
    $transliteration = $this->createMock(TransliterationInterface::class);
    $transliteration->expects($this->once())
      ->method('transliterate')
      ->with(trim($input), LanguageInterface::LANGCODE_DEFAULT, '_')
      ->willReturn($transliterated);

    $themeExtensionList = $this->createMock(ThemeExtensionList::class);
    $themeExtensionList->method('exists')
      ->willReturnCallback(static fn (string $name): bool => in_array($name, $existingThemes, TRUE));

    return new ThemeMachineNameFactory($transliteration, $themeExtensionList);
  }

}
