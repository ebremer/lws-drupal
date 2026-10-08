<?php

declare(strict_types=1);

namespace Drupal\Tests\lws\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the LWS URL space through Drupal's HTTP kernel, without storages.
 *
 * Covers what the lws module does before any route answers: malformed paths,
 * OPTIONS, problem details for every error, and leaving other paths to
 * Drupal. The storage module's tests cover the responses of real storages.
 */
#[Group('lws')]
#[RunTestsInSeparateProcesses]
final class UrlSpaceTest extends KernelTestBase {

  private const BASE = 'https://storage.example';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'lws'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // system.performance enables core's fast 404 pages, which LWS must
    // override; date formats are needed to render Drupal's own 404 page.
    $this->installConfig(['system', 'lws']);
    $this->installEntitySchema('date_format');
    $this->config('lws.settings')->set('base_url', self::BASE)->save();
  }

  /**
   * Sends a request through the HTTP kernel.
   */
  private function send(string $method, string $path): Response {
    return $this->container->get('http_kernel')->handle(Request::create(self::BASE . $path, $method));
  }

  /**
   * Asserts that a response is a problem details document.
   */
  private function assertProblem(Response $response, int $status, ?string $instance = NULL): void {
    $this->assertSame($status, $response->getStatusCode());
    $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));
    $problem = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertIsArray($problem);
    $this->assertSame($status, $problem['status']);
    $this->assertSame($instance, $problem['instance'] ?? NULL);
  }

  /**
   * Tests that without the storage module nothing exists.
   */
  public function testNoStorages(): void {
    $this->assertProblem($this->send('GET', '/lws/alice/'), 404, self::BASE . '/lws/alice/');
    $this->assertProblem($this->send('GET', '/lws/alice/root/'), 404, self::BASE . '/lws/alice/root/');
    // A name with a file extension gets problem details, not core's fast 404.
    $this->assertProblem($this->send('GET', '/lws/alice/root/notes.txt'), 404, self::BASE . '/lws/alice/root/notes.txt');
  }

  /**
   * Tests paths that are not valid LWS URLs.
   */
  public function testMalformed(): void {
    $paths = [
      // Core would redirect this to ".../a/b/", a different resource.
      '/lws/alice/root/a//b/',
      '/lws/alice/root/a/../b/',
      '/lws/alice/root/%2e%2e/',
      '/lws/alice/root/a%zz/',
      '/lws/alice/root/a%00/',
    ];
    foreach ($paths as $path) {
      foreach (['GET', 'POST', 'OPTIONS'] as $method) {
        $response = $this->send($method, $path);
        $this->assertProblem($response, 400);
        $problem = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($problem);
        $this->assertNotEmpty($problem['detail'], "$method $path");
      }
    }
  }

  /**
   * Tests paths at which nothing can exist.
   */
  public function testUnknown(): void {
    $paths = [
      '/lws/alice',
      '/lws/Alice/',
      '/lws/alice/notifications/',
      '/lws/alice/meta/0b5c3a5e-6a3e-4c1f-9d2e-3f1a2b3c4d5e/',
    ];
    foreach ($paths as $path) {
      $this->assertProblem($this->send('GET', $path), 404);
      $this->assertProblem($this->send('OPTIONS', $path), 404);
    }
  }

  /**
   * Tests OPTIONS, which core would otherwise answer for every route.
   *
   * The answer depends on the shape of the URL only, so it is the same
   * whether or not the resource exists.
   */
  public function testOptions(): void {
    $allow = [
      '/lws/alice/' => 'GET, HEAD, OPTIONS',
      '/lws/alice/root/' => 'GET, HEAD, POST, OPTIONS',
      '/lws/alice/root/notes/' => 'GET, HEAD, POST, DELETE, OPTIONS',
      '/lws/alice/root/notes' => 'GET, HEAD, PUT, DELETE, OPTIONS',
      '/lws/alice/meta/0b8f6a52-5ab1-4d2b-9a0a-3f6a1c2d4e5f' => 'GET, HEAD, OPTIONS',
    ];
    foreach ($allow as $path => $methods) {
      $response = $this->send('OPTIONS', $path);
      $this->assertSame(204, $response->getStatusCode(), $path);
      $this->assertSame($methods, $response->headers->get('Allow'), $path);
    }
  }

  /**
   * Tests that the internal paths cannot be requested directly.
   */
  public function testInternalPaths(): void {
    foreach (['/_lws/description', '/_lws/resource', '/_lws/meta', '/_lws/unknown'] as $path) {
      $response = $this->send('GET', $path);
      $this->assertSame(404, $response->getStatusCode(), $path);
      $this->assertStringNotContainsString('problem', (string) $response->headers->get('Content-Type'), $path);
    }
  }

  /**
   * Tests that reserved paths under the prefix are left to Drupal.
   */
  public function testReserved(): void {
    $response = $this->send('GET', '/lws/oauth/token');
    $this->assertSame(404, $response->getStatusCode());
    $this->assertNotSame('application/problem+json', $response->headers->get('Content-Type'));
  }

  /**
   * Tests that changing the prefix moves the URL space.
   */
  public function testPrefixChange(): void {
    $this->assertProblem($this->send('GET', '/lws/alice/root/'), 404, self::BASE . '/lws/alice/root/');
    $this->config('lws.settings')->set('prefix', '/storage')->save();
    $this->assertProblem($this->send('GET', '/storage/alice/root/'), 404, self::BASE . '/storage/alice/root/');
    $response = $this->send('GET', '/lws/alice/root/');
    $this->assertSame(404, $response->getStatusCode());
    $this->assertNotSame('application/problem+json', $response->headers->get('Content-Type'));
  }

}
