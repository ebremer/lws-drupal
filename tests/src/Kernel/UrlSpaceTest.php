<?php

declare(strict_types=1);

namespace Drupal\Tests\lws\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\lws\Http\LwsResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the LWS URL space through Drupal's HTTP kernel.
 *
 * Covers what Drupal's router does not do on its own: trailing slashes, case
 * and percent-encoding kept, paths of any depth, OPTIONS answered per
 * resource, and LWS errors not turned into HTML pages.
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
   *
   * @param string $method
   *   The HTTP method.
   * @param string $path
   *   The raw path, with any query string.
   */
  private function send(string $method, string $path): Response {
    return $this->container->get('http_kernel')->handle(Request::create(self::BASE . $path, $method));
  }

  /**
   * Decodes a JSON response body.
   *
   * @return array<string, mixed>
   *   The document.
   */
  private function json(Response $response): array {
    $body = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertIsArray($body);
    return $body;
  }

  /**
   * Asserts that a response is a problem details document.
   */
  private function assertProblem(Response $response, int $status, ?string $instance = NULL): void {
    $this->assertSame($status, $response->getStatusCode());
    $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));
    $problem = $this->json($response);
    $this->assertSame($status, $problem['status']);
    $this->assertSame($instance, $problem['instance'] ?? NULL);
  }

  /**
   * Tests the storage description and its headers.
   */
  public function testStorageDescription(): void {
    $response = $this->send('GET', '/lws/alice/');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('application/lws+cid', $response->headers->get('Content-Type'));
    $this->assertContains('<' . self::BASE . '/lws/alice/>; rel="https://www.w3.org/ns/lws#storage"', $response->headers->all('link'));
    // Core removes ETags from responses with the default Cache-Control.
    $this->assertNotEmpty($response->getEtag());
    $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));

    $description = $this->json($response);
    $this->assertSame(['https://www.w3.org/ns/cid/v1', 'https://www.w3.org/ns/lws/v1'], $description['@context']);
    $this->assertSame(self::BASE . '/lws/alice/', $description['id']);
    $this->assertSame([['type' => 'StorageRoot', 'serviceEndpoint' => self::BASE . '/lws/alice/root/']], $description['service']);
  }

  /**
   * Tests that a trailing slash tells a container from a data resource.
   */
  public function testTrailingSlash(): void {
    $container = $this->send('GET', '/lws/alice/root/notes/');
    $this->assertSame(200, $container->getStatusCode());
    $this->assertSame('application/lws+json', $container->headers->get('Content-Type'));
    $this->assertSame('"c0"', $container->getEtag());
    $links = $container->headers->all('link');
    $this->assertContains('<' . self::BASE . '/lws/alice/root/>; rel="up"', $links);
    $this->assertContains('<https://www.w3.org/ns/lws#Container>; rel="type"', $links);
    $this->assertContains('<' . self::BASE . '/lws/alice/>; rel="https://www.w3.org/ns/lws#storage"', $links);
    $this->assertSame([
      '@context' => 'https://www.w3.org/ns/lws/v1',
      'id' => self::BASE . '/lws/alice/root/notes/',
      'type' => 'Container',
      'totalItems' => 0,
      'items' => [],
    ], $this->json($container));

    // No data resources exist yet.
    $this->assertProblem($this->send('GET', '/lws/alice/root/notes'), 404, self::BASE . '/lws/alice/root/notes');
  }

  /**
   * Tests that the root container has no parent.
   */
  public function testRoot(): void {
    $response = $this->send('GET', '/lws/alice/root/');
    $this->assertSame(200, $response->getStatusCode());
    foreach ($response->headers->all('link') as $link) {
      $this->assertStringNotContainsString('rel="up"', (string) $link);
    }
  }

  /**
   * Tests that case and percent-encoding survive routing.
   */
  public function testCaseAndEncoding(): void {
    $paths = [
      '/lws/alice/root/Shopping%20List/' => '/lws/alice/root/Shopping%20List/',
      '/lws/alice/root/shopping%20list/' => '/lws/alice/root/shopping%20list/',
      '/lws/alice/root/%7Euser/' => '/lws/alice/root/~user/',
      '/lws/alice/root/caf%C3%A9/' => '/lws/alice/root/caf%C3%A9/',
    ];
    foreach ($paths as $path => $id) {
      $this->assertSame(self::BASE . $id, $this->json($this->send('GET', $path))['id'], $path);
    }
  }

  /**
   * Tests a deep path.
   */
  public function testDepth(): void {
    $path = '/lws/alice/root/' . implode('/', array_map(static fn (int $i): string => 'L' . $i, range(1, 25))) . '/';
    $this->assertSame(self::BASE . $path, $this->json($this->send('GET', $path))['id']);
  }

  /**
   * Tests that a cached route lookup still yields the right target.
   *
   * The router caches the processed path per URL and skips path processors on
   * a hit, so the target must not depend on them.
   */
  public function testRouteCache(): void {
    foreach (['/lws/alice/root/a/', '/lws/alice/root/a/', '/lws/alice/root/b/', '/lws/bob/root/a/'] as $path) {
      $this->assertSame(self::BASE . $path, $this->json($this->send('GET', $path))['id'], $path);
    }
  }

  /**
   * Tests that the query string plays no part in addressing.
   */
  public function testQueryString(): void {
    $this->assertSame(self::BASE . '/lws/alice/root/', $this->json($this->send('GET', '/lws/alice/root/?page=abc'))['id']);
  }

  /**
   * Tests HEAD.
   */
  public function testHead(): void {
    $response = $this->send('HEAD', '/lws/alice/root/notes/');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('application/lws+json', $response->headers->get('Content-Type'));
    $this->assertSame('"c0"', $response->getEtag());
    $this->assertNotEmpty($response->headers->all('link'));
  }

  /**
   * Tests OPTIONS, which core would otherwise answer for every route.
   */
  public function testOptions(): void {
    foreach (['/lws/alice/', '/lws/alice/root/', '/lws/alice/root/notes'] as $path) {
      $response = $this->send('OPTIONS', $path);
      $this->assertSame(204, $response->getStatusCode(), $path);
      $this->assertSame('GET, HEAD, OPTIONS', $response->headers->get('Allow'), $path);
    }
    $this->assertProblem($this->send('OPTIONS', '/lws/alice/nothing/'), 404);
    $this->assertProblem($this->send('OPTIONS', '/lws/alice/root/a/../b'), 400);
  }

  /**
   * Tests a method the resource does not support.
   */
  public function testMethodNotAllowed(): void {
    $response = $this->send('POST', '/lws/alice/root/');
    $this->assertProblem($response, 405, self::BASE . '/lws/alice/root/');
    $this->assertSame('GET, HEAD, OPTIONS', $response->headers->get('Allow'));
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
      $response = $this->send('GET', $path);
      $this->assertProblem($response, 400);
      $this->assertNotEmpty($this->json($response)['detail'], $path);
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
      '/lws/alice/meta/0b5c3a5e-6a3e-4c1f-9d2e-3f1a2b3c4d5e',
    ];
    foreach ($paths as $path) {
      $response = $this->send('GET', $path);
      $this->assertSame(404, $response->getStatusCode(), $path);
      $this->assertSame('application/problem+json', $response->headers->get('Content-Type'), $path);
    }
  }

  /**
   * Tests that a 404 for a name with a file extension is not core's fast 404.
   */
  public function testFast404(): void {
    $this->assertProblem($this->send('GET', '/lws/alice/root/notes.txt'), 404, self::BASE . '/lws/alice/root/notes.txt');
  }

  /**
   * Tests that the internal paths cannot be requested directly.
   */
  public function testInternalPaths(): void {
    foreach (['/_lws/description', '/_lws/resource', '/_lws/meta', '/_lws/unknown'] as $path) {
      $response = $this->send('GET', $path);
      $this->assertSame(404, $response->getStatusCode(), $path);
      $this->assertStringNotContainsString('lws', (string) $response->headers->get('Content-Type'), $path);
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
   * Tests the Cache-Control value that keeps core from removing ETags.
   */
  public function testCacheControl(): void {
    $response = $this->send('GET', '/lws/alice/root/');
    $this->assertNotSame('no-cache, private', $response->headers->get('Cache-Control'));
    $this->assertSame(
      $response->headers->get('Cache-Control'),
      (new Response('', 200, ['Cache-Control' => LwsResponse::CACHE_CONTROL]))->headers->get('Cache-Control'),
    );
  }

}
