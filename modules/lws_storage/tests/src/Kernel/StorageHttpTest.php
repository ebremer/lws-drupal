<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_storage\Kernel;

use Drupal\lws\Http\LwsResponse;
use Ebremer\Lws\Http\Headers;
use Ebremer\Lws\Model\ContainerPage;
use Ebremer\Lws\Model\ResourceMetadata;
use Ebremer\Lws\Model\StorageDescription;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests storage descriptions and containers over HTTP.
 *
 * Requests present a token of alice, the storage's controller. Responses are
 * also parsed with the PHP LWS client, so that the server's output is checked
 * against what clients expect.
 */
#[Group('lws')]
#[RunTestsInSeparateProcesses]
final class StorageHttpTest extends LwsStorageKernelTestBase {

  private const STORAGE = self::BASE . '/lws/alice/';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->storages->createStorage('alice', 'Alice', ['https://id.example/alice']);
    $this->agent = 'https://id.example/alice';
  }

  /**
   * Creates nested containers under the root of "alice".
   *
   * @param string ...$names
   *   The decoded names, outermost first.
   */
  private function containers(string ...$names): void {
    $storage = $this->loadStorage('alice');
    $parent = $this->resources->root($storage);
    foreach ($names as $name) {
      $parent = $this->resources->findByPath($storage, $parent->getPath() . $name . '/')
        ?? $this->storages->createContainer($parent, $name);
    }
  }

  /**
   * The response headers in the form the client library takes.
   */
  private function metadata(string $url, Response $response): ResourceMetadata {
    return new ResourceMetadata($url, $response->getStatusCode(), new Headers($response->headers->all()));
  }

  /**
   * Tests the storage description.
   */
  public function testStorageDescription(): void {
    $response = $this->send('GET', '/lws/alice/');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('application/lws+cid', $response->headers->get('Content-Type'));
    $this->assertSame('Accept', $response->headers->get('Vary'));
    $this->assertContains('<' . self::STORAGE . '>; rel="https://www.w3.org/ns/lws#storage"', $response->headers->all('link'));
    $this->assertNotEmpty($response->getEtag());

    $description = $this->json($response);
    $this->assertSame(['https://www.w3.org/ns/cid/v1', 'https://www.w3.org/ns/lws/v1'], $description['@context']);
    $parsed = StorageDescription::parse($description, self::STORAGE);
    $this->assertSame(self::STORAGE, $parsed->id);
    $this->assertSame(self::STORAGE . 'root/', $parsed->storageRoot());
  }

  /**
   * Tests content negotiation of the storage description.
   */
  public function testStorageDescriptionNegotiation(): void {
    $types = [
      'application/ld+json' => 'application/ld+json',
      'application/json' => 'application/json',
      '*/*' => 'application/lws+cid',
      'application/lws+cid, application/ld+json;q=0.9, application/json;q=0.8' => 'application/lws+cid',
    ];
    $etags = [];
    foreach ($types as $accept => $type) {
      $response = $this->send('GET', '/lws/alice/', ['Accept' => $accept]);
      $this->assertSame($type, $response->headers->get('Content-Type'), $accept);
      $etags[$type] = $response->getEtag();
    }
    // Each representation has its own entity tag.
    $this->assertCount(3, array_unique($etags));
    $this->assertProblem($this->send('GET', '/lws/alice/', ['Accept' => 'text/turtle']), 406, self::STORAGE);
  }

  /**
   * Tests storages that do not exist or are blocked.
   */
  public function testUnknownAndBlockedStorages(): void {
    $this->assertProblem($this->send('GET', '/lws/bob/'), 404, self::BASE . '/lws/bob/');
    $this->assertProblem($this->send('GET', '/lws/bob/root/'), 404, self::BASE . '/lws/bob/root/');

    $this->loadStorage('alice')->set('status', FALSE)->save();
    $this->assertProblem($this->send('GET', '/lws/alice/'), 503, self::STORAGE);
    $this->assertProblem($this->send('GET', '/lws/alice/root/'), 503, self::STORAGE . 'root/');
  }

  /**
   * Tests the empty root container.
   */
  public function testRootContainer(): void {
    $response = $this->send('GET', '/lws/alice/root/');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('application/lws+json', $response->headers->get('Content-Type'));
    $this->assertSame('"c1"', $response->getEtag());
    $this->assertNotNull($response->getLastModified());

    $page = ContainerPage::parse($this->json($response), $this->metadata(self::STORAGE . 'root/', $response));
    $this->assertSame(self::STORAGE . 'root/', $page->id);
    $this->assertTrue($page->isContainer());
    $this->assertSame(0, $page->totalItems);
    $this->assertSame([], $page->items);
    $this->assertSame(self::STORAGE, $page->metadata->storage);
    $this->assertNull($page->metadata->parent);
  }

  /**
   * Tests a container with members.
   */
  public function testContainerMembers(): void {
    $this->containers('Notes', 'a');
    $this->containers('Notes', 'b');
    $this->containers('Archive');

    $response = $this->send('GET', '/lws/alice/root/Notes/');
    $this->assertSame('"c3"', $response->getEtag());
    $page = ContainerPage::parse($this->json($response), $this->metadata(self::STORAGE . 'root/Notes/', $response));
    $this->assertSame(self::STORAGE . 'root/', $page->metadata->parent);
    $this->assertSame(2, $page->totalItems);
    $this->assertSame([self::STORAGE . 'root/Notes/a/', self::STORAGE . 'root/Notes/b/'], array_map(static fn ($item) => $item->id, $page->items));
    foreach ($page->items as $item) {
      $this->assertTrue($item->isContainer());
      $this->assertNotNull($item->modified);
    }

    // Members are ordered by name, case-sensitively.
    $root = $this->json($this->send('GET', '/lws/alice/root/'));
    $this->assertSame([self::STORAGE . 'root/Archive/', self::STORAGE . 'root/Notes/'], array_column($root['items'], 'id'));
  }

  /**
   * Tests content negotiation of containers.
   */
  public function testContainerNegotiation(): void {
    foreach (['application/lws+json', 'application/ld+json', 'application/json'] as $type) {
      $response = $this->send('GET', '/lws/alice/root/', ['Accept' => $type]);
      $this->assertSame($type, $response->headers->get('Content-Type'));
      $this->assertSame('Accept', $response->headers->get('Vary'));
    }
    $profile = 'application/ld+json; profile="https://www.w3.org/ns/lws/v1"';
    $this->assertSame($profile, $this->send('GET', '/lws/alice/root/', ['Accept' => $profile])->headers->get('Content-Type'));
    $this->assertProblem($this->send('GET', '/lws/alice/root/', ['Accept' => 'text/turtle']), 406, self::STORAGE . 'root/');
  }

  /**
   * Tests that a trailing slash tells a container from a data resource.
   */
  public function testTrailingSlash(): void {
    $this->containers('notes');
    $this->assertSame(200, $this->send('GET', '/lws/alice/root/notes/')->getStatusCode());
    $this->assertProblem($this->send('GET', '/lws/alice/root/notes'), 404, self::STORAGE . 'root/notes');
    // A 404 for a name with a file extension is not core's fast 404 page.
    $this->assertProblem($this->send('GET', '/lws/alice/root/notes.txt'), 404, self::STORAGE . 'root/notes.txt');
    $this->assertProblem($this->send('GET', '/lws/alice/root/missing/'), 404, self::STORAGE . 'root/missing/');
  }

  /**
   * Tests that case and percent-encoding survive routing.
   */
  public function testCaseAndEncoding(): void {
    $this->containers('Shopping List');
    $this->containers('shopping list');
    $this->containers('~user');
    $this->containers('café');
    $paths = [
      '/lws/alice/root/Shopping%20List/' => 'root/Shopping%20List/',
      '/lws/alice/root/shopping%20list/' => 'root/shopping%20list/',
      '/lws/alice/root/%7Euser/' => 'root/~user/',
      '/lws/alice/root/caf%C3%A9/' => 'root/caf%C3%A9/',
      '/lws/alice/root/caf%c3%a9/' => 'root/caf%C3%A9/',
    ];
    foreach ($paths as $path => $id) {
      $this->assertSame(self::STORAGE . $id, $this->json($this->send('GET', $path))['id'], $path);
    }
  }

  /**
   * Tests a deep path.
   */
  public function testDepth(): void {
    $names = array_map(static fn (int $i): string => 'L' . $i, range(1, 25));
    $this->containers(...$names);
    $path = '/lws/alice/root/' . implode('/', $names) . '/';
    $response = $this->send('GET', $path);
    $this->assertSame(self::BASE . $path, $this->json($response)['id']);
    $parent = '/lws/alice/root/' . implode('/', array_slice($names, 0, -1)) . '/';
    $this->assertContains('<' . self::BASE . $parent . '>; rel="up"', $response->headers->all('link'));
  }

  /**
   * Tests that cached route lookups still yield the right resource.
   *
   * The router caches the processed path per URL and skips path processors on
   * a hit, so the target must not depend on them.
   */
  public function testRouteCache(): void {
    $this->containers('a');
    $this->containers('b');
    foreach (['/lws/alice/root/a/', '/lws/alice/root/a/', '/lws/alice/root/b/', '/lws/alice/root/b/'] as $path) {
      $this->assertSame(self::BASE . $path, $this->json($this->send('GET', $path))['id'], $path);
    }
  }

  /**
   * Tests that a new member changes the container's validators.
   */
  public function testEtagFollowsMembership(): void {
    $before = $this->send('GET', '/lws/alice/root/')->getEtag();
    $this->containers('notes');
    $this->assertNotSame($before, $this->send('GET', '/lws/alice/root/')->getEtag());
  }

  /**
   * Tests HEAD, the query string, OPTIONS and unsupported methods.
   */
  public function testMethods(): void {
    $head = $this->send('HEAD', '/lws/alice/root/');
    $this->assertSame(200, $head->getStatusCode());
    $this->assertSame('"c1"', $head->getEtag());

    // Other query parameters are ignored, but a page that is not one of the
    // server's is not found.
    $this->assertSame(self::STORAGE . 'root/', $this->json($this->send('GET', '/lws/alice/root/?sort=name'))['id']);
    $this->assertProblem($this->send('GET', '/lws/alice/root/?page=abc'), 404, self::STORAGE . 'root/');

    // OPTIONS answers from the shape of the URL, existing or not.
    $allow = [
      '/lws/alice/root/' => 'GET, HEAD, POST, OPTIONS',
      '/lws/alice/root/missing/' => 'GET, HEAD, POST, DELETE, OPTIONS',
      '/lws/bob/' => 'GET, HEAD, OPTIONS',
    ];
    foreach ($allow as $path => $methods) {
      $response = $this->send('OPTIONS', $path);
      $this->assertSame(204, $response->getStatusCode(), $path);
      $this->assertSame($methods, $response->headers->get('Allow'), $path);
    }

    // So do refusals of the methods a URL does not take, before anything
    // else is checked.
    $put = $this->send('PUT', '/lws/alice/root/', ['Authorization' => '']);
    $this->assertProblem($put, 405, self::STORAGE . 'root/');
    $this->assertSame('GET, HEAD, POST, OPTIONS', $put->headers->get('Allow'));
    $this->assertProblem($this->send('PATCH', '/lws/alice/root/missing/'), 405, self::STORAGE . 'root/missing/');
  }

  /**
   * Tests the Cache-Control value that keeps core from removing ETags.
   */
  public function testCacheControl(): void {
    $response = $this->send('GET', '/lws/alice/root/');
    $this->assertNotSame('no-cache, private', $response->headers->get('Cache-Control'));
    $this->assertSame(
      (new Response('', 200, ['Cache-Control' => LwsResponse::CACHE_CONTROL]))->headers->get('Cache-Control'),
      $response->headers->get('Cache-Control'),
    );
  }

}
