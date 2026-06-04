<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools\Favicon;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\emulsify\Favicon\FaviconPackageGenerator;
use Drupal\file\FileInterface;

/**
 * Reuses Emulsify's favicon generator to sanitize stored SVG sources.
 */
final class EmulsifyFaviconSourceSanitizer implements FaviconSourceSanitizerInterface {

  /**
   * Creates the sanitizer.
   */
  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly TimeInterface $time,
    private readonly LockBackendInterface $lock,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function sanitizeSourceFile(FileInterface $file): string {
    $generator = new FaviconPackageGenerator(
      $this->fileSystem,
      $this->fileUrlGenerator,
      $this->configFactory,
      $this->cacheTagsInvalidator,
      $this->time,
      $this->lock,
    );
    $analysis = $generator->validateSourceFile($file, FALSE);

    return (string) ($analysis['sanitized_svg'] ?? '');
  }

}
