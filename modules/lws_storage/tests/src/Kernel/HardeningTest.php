<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_storage\Kernel;

use Drupal\lws\Http\LwsHttpException;
use Drupal\lws\Http\RequestBody;
use Drupal\lws_authz\Policy\AccessPolicy;
use Drupal\lws_storage\Entity\LwsResourceInterface;
use Drupal\lws_storage\Http\JsonPatches;
use Drupal\lws_storage\Linkset\Linksets;
use Ebremer\Lws\Http\LinkHeader;
use Ebremer\Lws\Json\JsonPatch;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the protections of step S5 (DESIGN.md §8.4).
 */
#[Group('lws')]
#[RunTestsInSeparateProcesses]
final class HardeningTest extends LwsStorageKernelTestBase {

  private const ALICE = 'https://id.example/alice';

  private const BOB = 'https://id.example/bob';

  private const STORAGE = self::BASE . '/lws/alice/';

  private const ROOT = self::STORAGE . 'root/';

  private const CONTAINER = '<https://www.w3.org/ns/lws#Container>; rel="type"';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->storages->createStorage('alice', 'Alice', [self::ALICE]);
    $this->agent = self::ALICE;
  }

  /**
   * Creates a resource and returns its URI.
   *
   * @param string $path
   *   The container's path.
   * @param string|null $slug
   *   The Slug.
   * @param string|null $body
   *   The content; NULL for a container.
   * @param array<string, string> $headers
   *   Further headers.
   */
  private function create(string $path, ?string $slug, ?string $body = NULL, array $headers = []): string {
    $headers += $body === NULL ? ['Link' => self::CONTAINER] : ['Content-Type' => 'text/plain'];
    if ($slug !== NULL) {
      $headers['Slug'] = $slug;
    }
    $response = $this->send('POST', $path, $headers, $body);
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    return (string) $response->headers->get('Location');
  }

  /**
   * The path of a URI on the test site.
   */
  private function path(string $uri): string {
    return substr($uri, strlen(self::BASE));
  }

  /**
   * Gives an agent access to the storage.
   *
   * @param string $assignee
   *   The agent.
   * @param list<string> $actions
   *   The actions.
   * @param string $targetType
   *   The target type, as a term.
   * @param list<string> $targets
   *   The target URIs.
   */
  private function share(string $assignee, array $actions, string $targetType, array $targets): void {
    $storage = $this->container->get('lws_storage.storage_registry')->get('alice');
    $this->assertNotNull($storage);
    $policy = $this->container->get('lws_authz.policy_parser')->parse(AccessPolicy::document($assignee, $actions, $targetType, $targets), $storage);
    $this->container->get('lws_authz.policy_store')->add($storage, $policy);
  }

  /**
   * The number of files that hold content.
   */
  private function storedFiles(): int {
    $directory = 'private://lws';
    return is_dir($directory) ? count($this->container->get('file_system')->scanDirectory($directory, '/.*/')) : 0;
  }

  /**
   * Tests that a body is as long as its Content-Length, and not too long.
   */
  public function testBodyLength(): void {
    // A body that ended early, as when a client goes away, is not kept.
    $short = ['Content-Type' => 'text/plain', 'Content-Length' => '10'];
    $this->assertProblem($this->send('POST', '/lws/alice/root/', $short, 'short'), 400, self::ROOT);
    $uri = $this->create('/lws/alice/root/', 'a.txt', 'whole');
    $this->assertProblem($this->send('PUT', $this->path($uri), ['Content-Type' => 'text/plain', 'Content-Length' => '9'], 'cut'), 400, $uri);
    $this->assertSame('whole', $this->body($this->send('GET', $this->path($uri))));
    $this->assertProblem($this->send('PUT', $this->path($uri), ['Content-Length' => 'many'], 'x'), 400, $uri);

    // Bodies too large are refused before they are read: past the largest
    // content, past the quota, or past what PHP hands over for a POST.
    $this->config('lws_storage.settings')->set('max_upload_bytes', 100)->save();
    $this->assertProblem($this->send('PUT', $this->path($uri), ['Content-Length' => '101'], 'x'), 413, $uri);
    $this->config('lws_storage.settings')->set('max_upload_bytes', 0)->save();
    $this->loadStorage('alice')->set('quota_bytes', 20)->save();
    $large = ['Content-Type' => 'text/plain', 'Content-Length' => '16'];
    $this->assertProblem($this->send('POST', '/lws/alice/root/', $large, str_repeat('x', 16)), 507, self::ROOT);
    // The 5 bytes it replaces count as free.
    $this->assertSame(204, $this->send('PUT', $this->path($uri), ['Content-Length' => '20'], str_repeat('y', 20))->getStatusCode());
    $post = RequestBody::postMaxSize();
    if ($post > 0) {
      $this->assertProblem($this->send('POST', '/lws/alice/root/', ['Content-Length' => (string) ($post + 1)], 'x'), 413, self::ROOT);
    }
    $this->assertSame(1, $this->storedFiles());
    $this->assertSame(20, $this->loadStorage('alice')->getUsedBytes());
  }

  /**
   * Tests that "notes" and "notes/" are never both taken.
   */
  public function testTwinNames(): void {
    $this->assertSame(self::ROOT . 'notes/', $this->create('/lws/alice/root/', 'notes'));
    $this->assertSame(self::ROOT . 'notes-1', $this->create('/lws/alice/root/', 'notes', 'x'));
    $this->assertSame(self::ROOT . 'memo', $this->create('/lws/alice/root/', 'memo', 'x'));
    $this->assertSame(self::ROOT . 'memo-1/', $this->create('/lws/alice/root/', 'memo'));
    $root = $this->resources->findByPath($this->loadStorage('alice'), 'root/');
    $this->assertInstanceOf(LwsResourceInterface::class, $root);
    $this->expectException(\InvalidArgumentException::class);
    $this->storages->createContainer($root, 'memo');
  }

  /**
   * Tests that a policy on "notes" covers nothing in "notes/".
   */
  public function testTwinPolicies(): void {
    $secret = $this->create($this->path($this->create('/lws/alice/root/', 'notes')), 'secret.txt', 'secret');
    $this->share(self::BOB, ['read', 'modify', 'delete'], 'StorageResource', [self::ROOT . 'notes']);
    $this->agent = self::BOB;
    $this->assertSame(403, $this->send('GET', $this->path($secret))->getStatusCode());
    $this->assertSame(403, $this->send('GET', '/lws/alice/root/notes/')->getStatusCode());
    $this->assertSame(403, $this->send('DELETE', $this->path($secret))->getStatusCode());
    // A policy for containers alone may name one without its slash.
    $this->share(self::BOB, ['read'], 'Container', [self::ROOT . 'notes']);
    $this->assertSame(200, $this->send('GET', '/lws/alice/root/notes/')->getStatusCode());
    $this->assertSame(403, $this->send('GET', $this->path($secret))->getStatusCode());
  }

  /**
   * Tests that link targets are URIs a Link header can carry as they are.
   */
  public function testLinksetTargets(): void {
    $uri = $this->create('/lws/alice/root/', 'l.txt', 'x');
    $linkset = self::linksetOf($this->send('GET', $this->path($uri)));
    $put = function (array $context) use ($linkset): Response {
      return $this->send('PUT', $this->path($linkset), ['Content-Type' => 'application/linkset+json'], (string) json_encode(['linkset' => [$context]]));
    };
    $anchor = ['anchor' => $uri];
    $forged = 'https://evil.example/s/>;rel="https://www.w3.org/ns/lws#storage",<urn:x';
    $this->assertProblem($put($anchor + ['type' => [['href' => $forged]]]), 422, $linkset);
    $this->assertProblem($put($anchor + ['type' => [['href' => 'urn:a b']]]), 422, $linkset);
    // LWS classes and server-managed relations, in any case.
    $this->assertProblem($put($anchor + ['type' => [['href' => 'HTTPS://WWW.W3.ORG/ns/lws#Container']]]), 409, $linkset);
    $this->assertProblem($put($anchor + ['HTTPS://WWW.W3.ORG/ns/lws#storage' => [['href' => 'https://evil.example/s/']]]), 409, $linkset);
    $types = array_map(static fn (int $i): array => ['href' => 'https://type.example/T' . $i], range(1, Linksets::MAX_TYPES + 1));
    $this->assertProblem($put($anchor + ['type' => $types]), 422, $linkset);
    $this->assertSame(204, $put($anchor + ['type' => array_slice($types, 0, Linksets::MAX_TYPES)])->getStatusCode());

    // A Link header cannot carry them in; one it cannot is ignored.
    $quoted = ['Content-Type' => 'text/plain', 'Link' => '<urn:a"b>; rel="type"'];
    $created = $this->send('POST', '/lws/alice/root/', $quoted, 'x');
    $this->assertSame(201, $created->getStatusCode());
    $links = self::linksOf($this->send('GET', $this->path((string) $created->headers->get('Location'))));
    $this->assertSame(['https://www.w3.org/ns/lws#DataResource'], array_values(array_map(
      static fn ($link) => $link->href,
      array_filter($links, static fn ($link): bool => $link->rel === 'type'),
    )));
  }

  /**
   * The links of a response.
   *
   * @return list<\Ebremer\Lws\Http\Link>
   *   The links.
   */
  private static function linksOf(Response $response): array {
    return LinkHeader::parse(array_filter($response->headers->all('link'), 'is_string'));
  }

  /**
   * The linkset URI a response links.
   */
  private static function linksetOf(Response $response): string {
    foreach (self::linksOf($response) as $link) {
      if ($link->rel === 'linkset') {
        return $link->href;
      }
    }
    throw new \RuntimeException('No linkset.');
  }

  /**
   * Tests the recursive-delete limit, which tells nothing of the count.
   */
  public function testRecursiveDeleteLimit(): void {
    $outer = $this->create('/lws/alice/root/', 'outer');
    $inner = $this->create($this->path($outer), 'inner');
    $this->create($this->path($inner), 'a.txt', 'a');
    $this->config('lws_storage.settings')->set('max_recursive_delete', 1)->save();
    $response = $this->send('DELETE', $this->path($outer), ['Depth' => 'infinity']);
    $this->assertProblem($response, 422, $outer);
    $this->assertStringNotContainsString('2', (string) $this->json($response)['detail']);
    $this->assertSame(200, $this->send('GET', $this->path($inner))->getStatusCode());
    $this->config('lws_storage.settings')->set('max_recursive_delete', 2)->save();
    $this->assertSame(204, $this->send('DELETE', $this->path($outer), ['Depth' => 'infinity'])->getStatusCode());
  }

  /**
   * Tests that nesting stops where paths would be too long to store.
   */
  public function testPathLength(): void {
    $path = '/lws/alice/root/';
    $depth = 0;
    do {
      $response = $this->send('POST', $path, ['Link' => self::CONTAINER, 'Slug' => str_repeat('n', 199) . $depth]);
      if ($response->getStatusCode() === 201) {
        $path = $this->path((string) $response->headers->get('Location'));
      }
      $depth++;
    } while ($response->getStatusCode() === 201 && $depth < 20);
    $this->assertProblem($response, 400, self::BASE . $path);
    $this->assertSame(10, $depth - 1);
  }

  /**
   * Tests listings for an agent who sees only part of a container.
   */
  public function testFilteredListing(): void {
    foreach (['h1.txt', 'h2.txt', 'h3.txt', 'h4.txt', 'z.txt'] as $name) {
      $this->create('/lws/alice/root/', $name, $name);
    }
    $this->loadStorage('alice')->set('page_size', 2)->save();
    // Bob may read the containers, and z.txt alone of the files.
    $this->share(self::BOB, ['read'], 'Container', [self::ROOT]);
    $this->share(self::BOB, ['read'], 'DataResource', [self::ROOT . 'z.txt']);

    $alice = $this->send('GET', '/lws/alice/root/');
    $this->agent = self::BOB;
    $bob = $this->send('GET', '/lws/alice/root/');
    $this->assertSame(200, $bob->getStatusCode());
    $this->assertSame(['z.txt'], array_map(static fn (array $item): string => basename($item['id']), $this->json($bob)['items']));
    $this->assertSame(1, $this->json($bob)['totalItems']);
    // The page is bob's own: its tag is not the container's, and nothing
    // tells when members hidden from bob change.
    $this->assertNotSame($alice->getEtag(), $bob->getEtag());
    $this->assertStringStartsWith('"f', (string) $bob->getEtag());
    $this->assertFalse($bob->headers->has('Last-Modified'));
    $this->assertStringContainsString('Authorization', (string) $bob->headers->get('Vary'));
    $this->assertStringContainsString('Authorization', (string) $alice->headers->get('Vary'));
    $this->assertSame(304, $this->send('GET', '/lws/alice/root/', ['If-None-Match' => (string) $bob->getEtag()])->getStatusCode());
    $this->agent = self::ALICE;
    $this->create('/lws/alice/root/', 'h5.txt', 'h5');
    $this->agent = self::BOB;
    $this->assertSame(304, $this->send('GET', '/lws/alice/root/', ['If-None-Match' => (string) $bob->getEtag()])->getStatusCode());

    // A page examines only so many members. One that ends among members
    // bob cannot see goes on from there, without naming them.
    $this->setSetting('lws_storage_scan_limit', 3);
    $first = $this->send('GET', '/lws/alice/root/');
    $this->assertSame([], $this->json($first)['items']);
    $next = NULL;
    foreach (self::linksOf($first) as $link) {
      if ($link->rel === 'next') {
        $next = $link->href;
      }
    }
    $this->assertNotNull($next);
    $cursor = substr($next, strpos($next, '?page=') + 6);
    $this->assertStringNotContainsString('h3.txt', (string) base64_decode(strtr($cursor, '-_', '+/')));
    $pages = [];
    while ($next !== NULL) {
      $page = $this->send('GET', $this->path($next));
      $pages[] = array_map(static fn (array $item): string => basename($item['id']), $this->json($page)['items']);
      $next = NULL;
      foreach (self::linksOf($page) as $link) {
        if ($link->rel === 'next') {
          $next = $link->href;
        }
      }
    }
    $this->assertSame(['z.txt'], array_merge(...$pages));
  }

  /**
   * Tests that every response of the LWS URL space is sandboxed.
   */
  public function testSandbox(): void {
    $this->create('/lws/alice/root/', 'a.txt', 'a');
    foreach (['/lws/alice/root/', '/lws/alice/root/a.txt', '/lws/alice/root/missing', '/lws/alice/'] as $path) {
      $this->assertSame('sandbox', $this->send('GET', $path)->headers->get('Content-Security-Policy'), $path);
    }
    $this->assertSame('sandbox', $this->send('OPTIONS', '/lws/alice/root/')->headers->get('Content-Security-Policy'));
  }

  /**
   * Tests that a storage may require If-Match whatever the site does.
   */
  public function testRequireIfMatchPerStorage(): void {
    $uri = $this->create('/lws/alice/root/', 'm.txt', 'x');
    $this->loadStorage('alice')->set('require_if_match', TRUE)->save();
    $this->assertProblem($this->send('PUT', $this->path($uri), ['Content-Type' => 'text/plain'], 'y'), 428, $uri);
    $this->config('lws_storage.settings')->set('require_if_match', TRUE)->save();
    $this->loadStorage('alice')->set('require_if_match', FALSE)->save();
    $this->assertSame(204, $this->send('PUT', $this->path($uri), ['Content-Type' => 'text/plain'], 'y')->getStatusCode());
  }

  /**
   * Tests the limits on patches.
   */
  public function testPatchLimits(): void {
    $uri = $this->create('/lws/alice/root/', 'j.json', '{"a":[1,2,3]}', ['Content-Type' => 'application/json']);
    $copies = array_fill(0, JsonPatches::MAX_COPIES + 1, ['op' => 'copy', 'from' => '/a', 'path' => '/b']);
    $patch = ['Content-Type' => 'application/json-patch+json'];
    $this->assertProblem($this->send('PATCH', $this->path($uri), $patch, (string) json_encode($copies)), 422, $uri);
    $this->assertProblem($this->send('PATCH', $this->path($uri), $patch + ['Content-Length' => (string) (JsonPatches::MAX_BYTES + 1)], '[]'), 413, $uri);

    // A document that doubles with each copy is refused once it is too large.
    $doubling = [];
    for ($i = 0; $i < 12; $i++) {
      $doubling[] = ['op' => 'copy', 'from' => '/a', 'path' => '/a/-'];
    }
    $this->expectException(LwsHttpException::class);
    JsonPatches::apply(JsonPatch::fromJson((string) json_encode($doubling)), ['a' => [str_repeat('x', 100)]], 10000);
  }

}
