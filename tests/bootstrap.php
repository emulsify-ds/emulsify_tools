<?php

declare(strict_types=1);

$autoloadCandidates = array_filter([
  __DIR__ . '/../vendor/autoload.php',
  getenv('EMULSIFY_TOOLS_TEST_AUTOLOAD') ?: NULL,
]);

$autoloadLoaded = FALSE;
$vendorDirectory = NULL;
foreach ($autoloadCandidates as $autoloadPath) {
  if (is_string($autoloadPath) && is_file($autoloadPath)) {
    require_once $autoloadPath;
    $autoloadLoaded = TRUE;
    $vendorDirectory = dirname($autoloadPath);
    break;
  }
}

if (!$autoloadLoaded) {
  fwrite(STDERR, "Unable to locate a Composer autoloader for the Emulsify Tools test suite.\n");
  exit(1);
}

spl_autoload_register(static function (string $class) use ($vendorDirectory): void {
  $prefixes = [
    'Drupal\\emulsify_tools\\' => __DIR__ . '/../src/',
    'Drupal\\Tests\\emulsify_tools\\' => __DIR__ . '/src/',
    'Drupal\\emulsify\\Favicon\\' => __DIR__ . '/fixtures/Drupal/emulsify/Favicon/',
  ];
  if (is_string($vendorDirectory) && is_dir($vendorDirectory . '/drupal/core/tests/Drupal/Tests/')) {
    $prefixes['Drupal\\Tests\\'] = $vendorDirectory . '/drupal/core/tests/Drupal/Tests/';
    $prefixes['Drupal\\TestTools\\'] = $vendorDirectory . '/drupal/core/tests/Drupal/TestTools/';
  }

  foreach ($prefixes as $prefix => $baseDirectory) {
    if (!str_starts_with($class, $prefix)) {
      continue;
    }

    $relativeClass = substr($class, strlen($prefix));
    $filePath = $baseDirectory . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($filePath)) {
      require_once $filePath;
    }

    return;
  }

  if (
    is_string($vendorDirectory)
    && preg_match('/^Drupal\\\\([^\\\\]+)\\\\(.+)$/', $class, $matches) === 1
  ) {
    $modulePath = $vendorDirectory . '/drupal/core/modules/' . $matches[1] . '/src/' . str_replace('\\', '/', $matches[2]) . '.php';
    if (is_file($modulePath)) {
      require_once $modulePath;
    }
  }
});
