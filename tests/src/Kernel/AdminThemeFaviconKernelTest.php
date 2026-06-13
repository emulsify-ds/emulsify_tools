<?php

declare(strict_types=1);

namespace Drupal\Tests\emulsify_tools\Kernel;

use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\Theme\ThemeInitializationInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\emulsify_tools\Hook\AdminThemeFaviconHooks;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Route;

/**
 * Tests admin theme favicon attachment integration.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
final class AdminThemeFaviconKernelTest extends EmulsifyToolsKernelTestBase {

  /**
   * Tests generated favicon package attachments on admin routes.
   */
  public function testAdminRouteAddsGeneratedFaviconPackageAndRemovesConflicts(): void {
    $this->configureFaviconIntegration(enabled: TRUE);
    $this->setRouteIsAdmin(TRUE);
    $this->setActiveTheme('stark');

    $attachments = $this->buildConflictingAttachments();
    $this->hooks()->pageAttachmentsAlter($attachments);

    $links = $this->headLinkAttributes($attachments);
    self::assertSame('/node/1', $links['canonical'][0]['href']);
    self::assertCount(2, $links['icon']);
    self::assertSame('/generated/favicon/favicon.ico', $links['icon'][0]['href']);
    self::assertSame('any', $links['icon'][0]['sizes']);
    self::assertSame('/generated/favicon/favicon.svg', $links['icon'][1]['href']);
    self::assertSame('image/svg+xml', $links['icon'][1]['type']);
    self::assertSame('/generated/favicon/apple-touch-icon.png', $links['apple-touch-icon'][0]['href']);
    self::assertSame('/generated/favicon/site.webmanifest', $links['manifest'][0]['href']);
    self::assertArrayNotHasKey('shortcut icon', $links);

    $metas = $this->headMetaContent($attachments);
    self::assertSame('#aabbcc', $metas['theme-color']);
    self::assertSame('Kernel Admin', $metas['apple-mobile-web-app-title']);
    self::assertSame('noindex', $metas['robots']);
    $this->assertAdminFaviconCacheability($attachments);
  }

  /**
   * Tests that non-admin routes do not receive admin favicon attachments.
   */
  public function testNonAdminRouteDoesNotAddGeneratedFaviconPackage(): void {
    $this->configureFaviconIntegration(enabled: TRUE);
    $this->setRouteIsAdmin(FALSE);
    $this->setActiveTheme('stark');

    $attachments = $this->buildConflictingAttachments();
    $expectedAttached = $attachments['#attached'];
    $this->hooks()->pageAttachmentsAlter($attachments);

    self::assertSame($expectedAttached, $attachments['#attached']);
    $this->assertAdminFaviconCacheability($attachments);
  }

  /**
   * Tests that disabled integrations do not add admin favicon attachments.
   */
  public function testDisabledToggleDoesNotAddGeneratedFaviconPackage(): void {
    $this->configureFaviconIntegration(enabled: FALSE);
    $this->setRouteIsAdmin(TRUE);
    $this->setActiveTheme('stark');

    $attachments = $this->buildConflictingAttachments();
    $expectedAttached = $attachments['#attached'];
    $this->hooks()->pageAttachmentsAlter($attachments);

    self::assertSame($expectedAttached, $attachments['#attached']);
    $this->assertAdminFaviconCacheability($attachments);
  }

  /**
   * Configures the module and frontend theme settings used by the tests.
   */
  private function configureFaviconIntegration(bool $enabled): void {
    $this->setDefaultTheme('emulsify_child');
    $this->config('system.theme')
      ->set('admin', 'stark')
      ->save();
    $this->config('system.site')
      ->set('name', 'Kernel Site')
      ->save();
    $this->config('emulsify_tools.settings')
      ->set('admin_theme_favicon_themes', $enabled ? ['emulsify_child'] : [])
      ->save(TRUE);
    $this->config('emulsify_child.settings')
      ->set('favicon_package_enabled', TRUE)
      ->set('favicon_package_path', '/generated/favicon')
      ->set('favicon_android_background_color', '#abc')
      ->set('favicon_ios_icon_name', 'Kernel Admin')
      ->save(TRUE);
  }

  /**
   * Sets the current route admin flag.
   */
  private function setRouteIsAdmin(bool $isAdmin): void {
    $route = new Route('/emulsify-tools-test');
    if ($isAdmin) {
      $route->setOption('_admin_route', TRUE);
    }

    $request = Request::create('/emulsify-tools-test');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'emulsify_tools.kernel_test');
    $this->container->get('request_stack')->push($request);
  }

  /**
   * Sets the active Drupal theme.
   */
  private function setActiveTheme(string $themeName): void {
    $activeTheme = $this->container
      ->get(ThemeInitializationInterface::class)
      ->getActiveThemeByName($themeName);
    $this->container
      ->get(ThemeManagerInterface::class)
      ->setActiveTheme($activeTheme);
  }

  /**
   * Returns a resolved hook handler.
   */
  private function hooks(): AdminThemeFaviconHooks {
    return $this->container
      ->get('class_resolver')
      ->getInstanceFromDefinition(AdminThemeFaviconHooks::class);
  }

  /**
   * Returns representative attachments with core favicon conflicts.
   *
   * @return array<string|int, mixed>
   *   Page attachments.
   */
  private function buildConflictingAttachments(): array {
    return [
      '#attached' => [
        'html_head_link' => [
          [
            [
              'rel' => 'shortcut icon',
              'href' => '/core/misc/favicon.ico',
            ],
            FALSE,
          ],
          [
            [
              'rel' => 'icon',
              'href' => '/core/misc/favicon.ico',
            ],
            FALSE,
          ],
          [
            [
              'rel' => 'manifest',
              'href' => '/core/misc/manifest.webmanifest',
            ],
            FALSE,
          ],
          [
            [
              'rel' => 'canonical',
              'href' => '/node/1',
            ],
            TRUE,
          ],
        ],
        'html_head' => [
          [
            [
              '#tag' => 'meta',
              '#attributes' => [
                'name' => 'theme-color',
                'content' => '#000000',
              ],
            ],
            'core_theme_color',
          ],
          [
            [
              '#tag' => 'meta',
              '#attributes' => [
                'name' => 'robots',
                'content' => 'noindex',
              ],
            ],
            'robots',
          ],
        ],
      ],
    ];
  }

  /**
   * Returns head link attributes keyed by rel.
   *
   * @param array<string|int, mixed> $attachments
   *   Page attachments.
   *
   * @return array<string, list<array<string, mixed>>>
   *   Head links keyed by rel.
   */
  private function headLinkAttributes(array $attachments): array {
    $links = [];
    foreach ($attachments['#attached']['html_head_link'] as $link) {
      $attributes = $link[0];
      $links[(string) $attributes['rel']][] = $attributes;
    }

    return $links;
  }

  /**
   * Returns meta content keyed by meta name.
   *
   * @param array<string|int, mixed> $attachments
   *   Page attachments.
   *
   * @return array<string, string>
   *   Meta content keyed by meta name.
   */
  private function headMetaContent(array $attachments): array {
    $metas = [];
    foreach ($attachments['#attached']['html_head'] as $item) {
      $attributes = $item[0]['#attributes'];
      $metas[(string) $attributes['name']] = (string) $attributes['content'];
    }

    return $metas;
  }

  /**
   * Asserts cacheability metadata for the admin favicon decision.
   *
   * @param array<string|int, mixed> $attachments
   *   Page attachments.
   */
  private function assertAdminFaviconCacheability(array $attachments): void {
    self::assertContains('config:emulsify_tools.settings', $attachments['#cache']['tags']);
    self::assertContains('config:system.theme', $attachments['#cache']['tags']);
    self::assertContains('config:emulsify_child.settings', $attachments['#cache']['tags']);
    self::assertContains('route', $attachments['#cache']['contexts']);
  }

}
