<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_storage\Kernel;

use Drupal\file\FileInterface;
use Drupal\lws_storage\Entity\LwsResourceInterface;
use Drupal\lws_storage\StorageManager;
use Drupal\lws_storage_test\EventSubscriber\RejectingValidator;
use Drupal\lws_storage_test\Hook\RaceHooks;
use Ebremer\Lws\Http\Headers;
use Ebremer\Lws\Http\LinkHeader;
use Ebremer\Lws\Model\ContainerPage;
use Ebremer\Lws\Model\Linkset;
use Ebremer\Lws\Model\ResourceMetadata;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests creating, reading, replacing and deleting resources (LWS Core §9).
 */
#[Group('lws')]
#[RunTestsInSeparateProcesses]
final class ResourceOperationsTest extends LwsStorageKernelTestBase {

  private const STORAGE = self::BASE . '/lws/alice/';

  private const ROOT = self::STORAGE . 'root/';

  private const CONTAINER = '<https://www.w3.org/ns/lws#Container>; rel="type"';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['lws_storage_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->storages->createStorage('alice', 'Alice', ['https://id.example/alice']);
    $this->agent = 'https://id.example/alice';
  }

  /**
   * Creates a data resource and returns its URI.
   *
   * @param string $path
   *   The container's path.
   * @param string $body
   *   The content.
   * @param array<string, string> $headers
   *   Further headers, such as Slug.
   */
  private function post(string $path, string $body, array $headers = []): string {
    $response = $this->send('POST', $path, $headers + ['Content-Type' => 'text/plain'], $body);
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    return (string) $response->headers->get('Location');
  }

  /**
   * Creates a container and returns its URI.
   */
  private function postContainer(string $path, ?string $slug = NULL): string {
    $headers = ['Link' => self::CONTAINER];
    if ($slug !== NULL) {
      $headers['Slug'] = $slug;
    }
    $response = $this->send('POST', $path, $headers);
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
   * Parses a container listing with the PHP LWS client.
   */
  private function listing(string $uri): ContainerPage {
    $response = $this->send('GET', $this->path($uri));
    $this->assertSame(200, $response->getStatusCode());
    return ContainerPage::parse($this->json($response), new ResourceMetadata($uri, 200, new Headers($response->headers->all())));
  }

  /**
   * The link targets of a response, by relation.
   *
   * @return array<string, list<string>>
   *   Targets by relation.
   */
  private function links(Response $response): array {
    $links = [];
    foreach (LinkHeader::parse(array_filter($response->headers->all('link'), 'is_string')) as $link) {
      $links[$link->rel][] = $link->href;
    }
    return $links;
  }

  /**
   * The resource at a URI, freshly loaded.
   */
  private function resource(string $uri): ?LwsResourceInterface {
    return $this->resources->findByPath($this->loadStorage('alice'), rawurldecode(substr($uri, strlen(self::STORAGE))));
  }

  /**
   * Tests creating and reading a data resource.
   */
  public function testCreateAndRead(): void {
    $before = $this->send('GET', '/lws/alice/root/')->getEtag();
    $response = $this->send('POST', '/lws/alice/root/', ['Content-Type' => 'text/plain', 'Slug' => 'shopping list.txt'], "0123456789");
    $this->assertSame(201, $response->getStatusCode());
    $uri = self::ROOT . 'shopping-list.txt';
    $this->assertSame($uri, $response->headers->get('Location'));
    $links = $this->links($response);
    $this->assertSame([self::ROOT], $links['up']);
    $this->assertSame(['https://www.w3.org/ns/lws#DataResource'], $links['type']);
    $this->assertSame([self::STORAGE], $links['https://www.w3.org/ns/lws#storage']);
    $this->assertStringStartsWith(self::STORAGE . 'meta/', $links['linkset'][0]);
    $this->assertStringContainsString('type="application/linkset+json"', implode(',', $response->headers->all('link')));
    $etag = $response->getEtag();
    $this->assertNotNull($etag);

    $read = $this->send('GET', '/lws/alice/root/shopping-list.txt');
    $this->assertSame(200, $read->getStatusCode());
    $this->assertSame('0123456789', $this->body($read));
    $this->assertSame('text/plain', $read->headers->get('Content-Type'));
    $this->assertSame($etag, $read->getEtag());
    $this->assertNotNull($read->getLastModified());
    $this->assertSame('bytes', $read->headers->get('Accept-Ranges'));
    $this->assertSame('GET, HEAD, PUT, DELETE, OPTIONS', $read->headers->get('Allow'));
    $this->assertSame('sandbox', $read->headers->get('Content-Security-Policy'));
    $this->assertSame('nosniff', $read->headers->get('X-Content-Type-Options'));
    $this->assertSame($this->links($response), $this->links($read));

    $head = $this->send('HEAD', '/lws/alice/root/shopping-list.txt');
    $this->assertSame(200, $head->getStatusCode());
    $this->assertSame('', $this->body($head));
    $this->assertSame($etag, $head->getEtag());
    $this->assertSame('10', $head->headers->get('Content-Length'));

    // The container lists it, and its entity tag changed.
    $page = $this->listing(self::ROOT);
    $this->assertSame(1, $page->totalItems);
    $this->assertSame($uri, $page->items[0]->id);
    $this->assertSame('text/plain', $page->items[0]->format);
    $this->assertSame(10, $page->items[0]->size);
    $this->assertNotSame($before, $this->send('GET', '/lws/alice/root/')->getEtag());

    // The storage counts the bytes.
    $this->assertSame(10, $this->loadStorage('alice')->getUsedBytes());
    // The creator is recorded.
    $this->assertSame('https://id.example/alice', $this->resource($uri)?->get('creator')->value);
  }

  /**
   * Tests names from identity hints, and generated ones.
   */
  public function testNames(): void {
    $this->assertSame(self::ROOT . 'a.txt', $this->post('/lws/alice/root/', 'x', ['Slug' => 'a.txt']));
    $this->assertSame(self::ROOT . 'a-1.txt', $this->post('/lws/alice/root/', 'x', ['Slug' => 'a.txt']));
    $this->assertSame(self::ROOT . 'a-2.txt', $this->post('/lws/alice/root/', 'x', ['Slug' => 'a.txt']));
    $this->assertSame(self::ROOT . 'profile', $this->post('/lws/alice/root/', 'x', ['Slug' => '.profile']));
    $this->assertSame(self::ROOT . 'etc-passwd', $this->post('/lws/alice/root/', 'x', ['Slug' => '../../etc/passwd']));
    $this->assertSame(self::ROOT . 'caf-menu.txt', $this->post('/lws/alice/root/', 'x', ['Slug' => 'caf%C3%A9 menu.txt']));
    // An insecure extension to core's uploads is just a name here.
    $this->assertSame(self::ROOT . 'app.js', $this->post('/lws/alice/root/', 'x', ['Slug' => 'app.js']));

    $first = $this->post('/lws/alice/root/', 'same bytes');
    $second = $this->post('/lws/alice/root/', 'same bytes');
    $this->assertNotSame($first, $second);
    $this->assertMatchesRegularExpression('#^' . preg_quote(self::ROOT, '#') . '[0-9a-f-]{36}$#', $first);

    $this->assertSame(self::ROOT . 'notes/', $this->postContainer('/lws/alice/root/', 'notes'));
    $this->assertSame(self::ROOT . 'notes-1/', $this->postContainer('/lws/alice/root/', 'notes'));
  }

  /**
   * Tests that a create that loses a race for a name takes the next one.
   */
  public function testRaceForName(): void {
    $this->container->get('state')->set(RaceHooks::STATE, 'raced.txt');
    $this->assertSame(self::ROOT . 'raced-1.txt', $this->post('/lws/alice/root/', 'x', ['Slug' => 'raced.txt']));
    // The rolled-back attempt left nothing behind.
    $names = array_map(static fn ($item) => $item->id, $this->listing(self::ROOT)->items);
    $this->assertSame([self::ROOT . 'raced-1.txt'], $names);
    $this->assertSame(2, $this->resources->root($this->loadStorage('alice'))->getVersion());
    $this->assertCount(1, $this->container->get('entity_type.manager')->getStorage('file')->loadMultiple());
  }

  /**
   * Tests creating containers, and creating in them.
   */
  public function testContainers(): void {
    $notes = $this->postContainer('/lws/alice/root/', 'notes');
    $response = $this->send('GET', $this->path($notes));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame([], $this->json($response)['items']);
    $this->assertSame([self::ROOT], $this->links($response)['up']);
    $this->assertSame(['https://www.w3.org/ns/lws#Container'], $this->links($response)['type']);
    $this->assertSame('GET, HEAD, POST, DELETE, OPTIONS', $response->headers->get('Allow'));

    $leaf = $this->post($this->path($notes), 'leaf', ['Slug' => 'leaf.txt']);
    $this->assertSame($notes . 'leaf.txt', $leaf);
    $this->assertSame([$notes], $this->links($this->send('HEAD', $this->path($leaf)))['up']);
    $this->assertTrue($this->listing(self::ROOT)->items[0]->isContainer());

    // A body sent with a container is ignored.
    $response = $this->send('POST', '/lws/alice/root/', ['Link' => self::CONTAINER, 'Content-Type' => 'text/plain'], 'ignored');
    $this->assertSame(201, $response->getStatusCode());
    $this->assertSame(strlen('leaf'), $this->loadStorage('alice')->getUsedBytes());
  }

  /**
   * Tests the requests a create refuses.
   */
  public function testCreateErrors(): void {
    $this->assertProblem($this->send('POST', '/lws/alice/root/missing/', ['Content-Type' => 'text/plain'], 'x'), 404, self::ROOT . 'missing/');
    $file = $this->post('/lws/alice/root/', 'x', ['Slug' => 'file.txt']);
    $response = $this->send('POST', $this->path($file), ['Content-Type' => 'text/plain'], 'x');
    $this->assertProblem($response, 405, $file);
    $this->assertSame('GET, HEAD, PUT, DELETE, OPTIONS', $response->headers->get('Allow'));
    $this->assertProblem($this->send('POST', '/lws/alice/root/', ['Content-Type' => 'not a type'], 'x'), 400, self::ROOT);
    $this->assertProblem($this->send('POST', '/lws/alice/root/', ['Content-Type' => 'multipart/form-data; boundary=x'], 'x'), 415, self::ROOT);
    $this->assertCount(1, $this->listing(self::ROOT)->items);

    // Without a Content-Type the content is application/octet-stream.
    $bytes = $this->post('/lws/alice/root/', "\x00\x01", ['Content-Type' => '']);
    $this->assertSame('application/octet-stream', $this->send('GET', $this->path($bytes))->headers->get('Content-Type'));

    // A forged linkset link is ignored.
    $forged = ['Content-Type' => 'text/plain', 'Link' => '<https://linkset.invalid/forged>; rel="linkset"'];
    $response = $this->send('POST', '/lws/alice/root/', $forged, 'x');
    $this->assertNotContains('https://linkset.invalid/forged', $this->links($response)['linkset']);
  }

  /**
   * Tests byte ranges (RFC 9110 §14).
   */
  public function testRanges(): void {
    $uri = $this->path($this->post('/lws/alice/root/', '0123456789', ['Slug' => 'digits.txt']));
    $response = $this->send('GET', $uri, ['Range' => 'bytes=0-3']);
    $this->assertSame(206, $response->getStatusCode());
    $this->assertSame('bytes 0-3/10', $response->headers->get('Content-Range'));
    $this->assertSame('0123', $this->body($response));
    $this->assertSame('89', $this->body($this->send('GET', $uri, ['Range' => 'bytes=-2'])));
    $unsatisfiable = $this->send('GET', $uri, ['Range' => 'bytes=100-200']);
    $this->assertSame(416, $unsatisfiable->getStatusCode());
    $this->assertSame('bytes */10', $unsatisfiable->headers->get('Content-Range'));
    $this->assertSame('0', $unsatisfiable->headers->get('Content-Length'));
    $this->assertSame('', $this->body($unsatisfiable));

    $etag = $this->send('GET', $uri)->getEtag();
    $this->assertSame(206, $this->send('GET', $uri, ['Range' => 'bytes=0-3', 'If-Range' => (string) $etag])->getStatusCode());
    $stale = $this->send('GET', $uri, ['Range' => 'bytes=0-3', 'If-Range' => '"stale"']);
    $this->assertSame(200, $stale->getStatusCode());
    $this->assertSame('0123456789', $this->body($stale));
  }

  /**
   * Tests conditional reads of data resources and containers.
   */
  public function testConditionalReads(): void {
    $uri = $this->path($this->post('/lws/alice/root/', 'date validator check', ['Slug' => 'c.txt']));
    $read = $this->send('GET', $uri);
    $etag = (string) $read->getEtag();
    $lastModified = (string) $read->headers->get('Last-Modified');

    $notModified = $this->send('GET', $uri, ['If-None-Match' => $etag]);
    $this->assertSame(304, $notModified->getStatusCode());
    $this->assertSame('', $this->body($notModified));
    $this->assertSame($etag, $notModified->getEtag());
    $this->assertSame(200, $this->send('GET', $uri, ['If-None-Match' => '"never-a-real-etag"'])->getStatusCode());
    $this->assertSame(304, $this->send('GET', $uri, ['If-Modified-Since' => $lastModified])->getStatusCode());
    $this->assertSame(200, $this->send('GET', $uri, ['If-Modified-Since' => 'Thu, 01 Jan 1970 00:00:00 GMT'])->getStatusCode());
    $this->assertSame(200, $this->send('GET', $uri, ['If-Unmodified-Since' => $lastModified])->getStatusCode());
    $this->assertProblem($this->send('GET', $uri, ['If-Unmodified-Since' => 'Thu, 01 Jan 1970 00:00:00 GMT']), 412, self::BASE . $uri);

    $container = (string) $this->send('GET', '/lws/alice/root/')->getEtag();
    $this->assertSame(304, $this->send('GET', '/lws/alice/root/', ['If-None-Match' => $container])->getStatusCode());
  }

  /**
   * Tests replacing content (§9.4).
   */
  public function testReplace(): void {
    $uri = $this->post('/lws/alice/root/', 'first content', ['Slug' => 'r.txt']);
    $path = $this->path($uri);
    $stale = (string) $this->send('GET', $path)->getEtag();
    $oldFile = $this->resource($uri)?->getContentFile();
    $this->assertInstanceOf(FileInterface::class, $oldFile);
    $oldUri = (string) $oldFile->getFileUri();
    $container = $this->send('GET', '/lws/alice/root/')->getEtag();

    $response = $this->send('PUT', $path, ['Content-Type' => 'text/markdown', 'If-Match' => $stale], 'second content');
    $this->assertSame(204, $response->getStatusCode());
    $etag = $response->getEtag();
    $this->assertNotSame($stale, $etag);
    $read = $this->send('GET', $path);
    $this->assertSame('second content', $this->body($read));
    $this->assertSame('text/markdown', $read->headers->get('Content-Type'));
    $this->assertSame($etag, $read->getEtag());
    $this->assertNotSame($container, $this->send('GET', '/lws/alice/root/')->getEtag());
    $this->assertSame(14, $this->loadStorage('alice')->getUsedBytes());

    // The former version is gone, file entity and bytes.
    $this->assertNull($this->container->get('entity_type.manager')->getStorage('file')->load((int) $oldFile->id()));
    $this->assertFileDoesNotExist($oldUri);

    // A stale entity tag changes nothing.
    $this->assertProblem($this->send('PUT', $path, ['Content-Type' => 'text/plain', 'If-Match' => $stale], 'lost update'), 412, $uri);
    $this->assertSame('second content', $this->body($this->send('GET', $path)));
    $this->assertProblem($this->send('PUT', $path, ['Content-Type' => 'text/plain', 'If-None-Match' => '*'], 'x'), 412, $uri);

    // The same bytes and type keep the entity tag; no Content-Type keeps the
    // type.
    $this->assertSame($etag, $this->send('PUT', $path, [], 'second content')->getEtag());
    $this->assertSame('text/markdown', $this->send('GET', $path)->headers->get('Content-Type'));

    // There is no create-by-PUT, and containers take no PUT.
    $this->assertProblem($this->send('PUT', '/lws/alice/root/missing.txt', ['Content-Type' => 'text/plain'], 'x'), 404, self::ROOT . 'missing.txt');
    $this->assertProblem($this->send('PUT', '/lws/alice/root/', ['Content-Type' => 'text/plain'], 'x'), 405, self::ROOT);
  }

  /**
   * Tests deleting data resources and containers (§9.5).
   */
  public function testDelete(): void {
    $uri = $this->post('/lws/alice/root/', 'doomed', ['Slug' => 'd.txt']);
    $fileUri = (string) $this->resource($uri)?->getContentFile()?->getFileUri();
    $linkset = $this->links($this->send('GET', $this->path($uri)))['linkset'][0];
    $etag = (string) $this->send('GET', $this->path($uri))->getEtag();
    $container = $this->send('GET', '/lws/alice/root/')->getEtag();

    $this->assertProblem($this->send('DELETE', $this->path($uri), ['If-Match' => '"stale"']), 412, $uri);
    $this->assertSame(204, $this->send('DELETE', $this->path($uri), ['If-Match' => $etag])->getStatusCode());
    $this->assertProblem($this->send('GET', $this->path($uri)), 404, $uri);
    $this->assertProblem($this->send('GET', $this->path($linkset)), 404, $linkset);
    $this->assertSame([], $this->listing(self::ROOT)->items);
    $this->assertNotSame($container, $this->send('GET', '/lws/alice/root/')->getEtag());
    $this->assertFileDoesNotExist($fileUri);
    $this->assertSame(0, $this->loadStorage('alice')->getUsedBytes());
    $this->assertProblem($this->send('DELETE', $this->path($uri)), 404, $uri);

    // A container that is not empty needs Depth: infinity.
    $outer = $this->postContainer('/lws/alice/root/', 'outer');
    $inner = $this->postContainer($this->path($outer), 'inner');
    $leaf = $this->post($this->path($inner), 'leaf', ['Slug' => 'leaf.txt']);
    $leafFile = (string) $this->resource($leaf)?->getContentFile()?->getFileUri();
    $this->assertProblem($this->send('DELETE', $this->path($outer)), 409, $outer);
    $this->assertSame(200, $this->send('GET', $this->path($leaf))->getStatusCode());
    $this->assertProblem($this->send('DELETE', $this->path($outer), ['Depth' => '1']), 400, $outer);

    $this->assertSame(204, $this->send('DELETE', $this->path($outer), ['Depth' => 'infinity'])->getStatusCode());
    foreach ([$outer, $inner, $leaf] as $gone) {
      $this->assertProblem($this->send('GET', $this->path($gone)), 404, $gone);
    }
    $this->assertSame([], $this->listing(self::ROOT)->items);
    $this->assertSame(0, $this->loadStorage('alice')->getUsedBytes());

    // The files of a recursive delete are left to the queue.
    $this->assertFileExists($leafFile);
    $queue = $this->container->get('queue')->get(StorageManager::GC_QUEUE);
    $this->assertSame(1, $queue->numberOfItems());
    $this->container->get('cron')->run();
    $this->assertFileDoesNotExist($leafFile);

    // The root cannot be deleted.
    $this->assertProblem($this->send('DELETE', '/lws/alice/root/'), 405, self::ROOT);
  }

  /**
   * Tests the If-Match requirement, when a site sets it.
   */
  public function testRequireIfMatch(): void {
    $uri = $this->post('/lws/alice/root/', 'x', ['Slug' => 'm.txt']);
    $this->config('lws_storage.settings')->set('require_if_match', TRUE)->save();
    $this->assertProblem($this->send('PUT', $this->path($uri), ['Content-Type' => 'text/plain'], 'y'), 428, $uri);
    $this->assertProblem($this->send('DELETE', $this->path($uri)), 428, $uri);
    $etag = (string) $this->send('GET', $this->path($uri))->getEtag();
    $this->assertSame(204, $this->send('DELETE', $this->path($uri), ['If-Match' => $etag])->getStatusCode());
  }

  /**
   * Tests quotas and size limits.
   */
  public function testLimits(): void {
    $storage = $this->loadStorage('alice');
    $storage->set('quota_bytes', 15)->save();
    $uri = $this->post('/lws/alice/root/', '0123456789', ['Slug' => 'q.txt']);
    $this->assertProblem($this->send('POST', '/lws/alice/root/', ['Content-Type' => 'text/plain'], '0123456789'), 507, self::ROOT);
    $this->assertCount(1, $this->listing(self::ROOT)->items);
    $this->assertSame(10, $this->loadStorage('alice')->getUsedBytes());
    // Growing within the quota is fine; beyond it is not.
    $this->assertSame(204, $this->send('PUT', $this->path($uri), [], '012345678901234')->getStatusCode());
    $this->assertProblem($this->send('PUT', $this->path($uri), [], '0123456789012345'), 507, $uri);
    $this->assertSame(15, $this->loadStorage('alice')->getUsedBytes());

    $this->config('lws_storage.settings')->set('max_upload_bytes', 5)->save();
    $this->assertProblem($this->send('POST', '/lws/alice/root/', ['Content-Type' => 'text/plain'], '123456'), 413, self::ROOT);

    // Nothing refused left a file behind.
    $this->assertCount(1, $this->container->get('entity_type.manager')->getStorage('file')->loadMultiple());
    $this->assertCount(1, $this->container->get('file_system')->scanDirectory('private://lws', '/.*/'));
  }

  /**
   * Tests that file validators other modules attach apply (§5.3).
   */
  public function testFileValidation(): void {
    $response = $this->send('POST', '/lws/alice/root/', ['Content-Type' => 'text/plain'], str_repeat('x', RejectingValidator::REJECTED_SIZE));
    $this->assertProblem($response, 422, self::ROOT);
    $this->assertStringContainsString('test validator', $this->json($response)['detail']);
    $this->assertSame([], $this->listing(self::ROOT)->items);
    $this->assertSame([], $this->container->get('file_system')->scanDirectory('private://lws', '/.*/'));
  }

  /**
   * Tests linksets, read-only until step S4 (§9.1).
   */
  public function testLinkset(): void {
    $uri = $this->post('/lws/alice/root/', 'x', ['Slug' => 'l.txt']);
    $linkset = $this->links($this->send('GET', $this->path($uri)))['linkset'][0];
    $response = $this->send('GET', $this->path($linkset));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('application/linkset+json', $response->headers->get('Content-Type'));
    $this->assertSame('GET, HEAD, OPTIONS', $response->headers->get('Allow'));
    $this->assertSame([self::STORAGE], $this->links($response)['https://www.w3.org/ns/lws#storage']);
    $parsed = Linkset::parse($this->json($response));
    $this->assertSame([self::ROOT], $parsed->hrefs('up', $uri));
    $this->assertSame(['https://www.w3.org/ns/lws#DataResource'], $parsed->hrefs('type', $uri));
    $this->assertSame(304, $this->send('GET', $this->path($linkset), ['If-None-Match' => (string) $response->getEtag()])->getStatusCode());

    // The root's linkset has no "up".
    $root = $this->links($this->send('GET', '/lws/alice/root/'))['linkset'][0];
    $this->assertSame([], Linkset::parse($this->json($this->send('GET', $this->path($root))))->hrefs('up'));
    $this->assertProblem($this->send('GET', '/lws/alice/meta/0b8f6a52-5ab1-4d2b-9a0a-3f6a1c2d4e5f'), 404, self::STORAGE . 'meta/0b8f6a52-5ab1-4d2b-9a0a-3f6a1c2d4e5f');
  }

  /**
   * Tests that writes need permission, like reads.
   */
  public function testAccess(): void {
    $uri = $this->post('/lws/alice/root/', 'x', ['Slug' => 'p.txt']);
    $linkset = $this->links($this->send('GET', $this->path($uri)))['linkset'][0];
    $bob = 'Bearer ' . $this->token('https://id.example/bob');
    $this->assertSame(403, $this->send('POST', '/lws/alice/root/', ['Authorization' => $bob], 'x')->getStatusCode());
    $this->assertSame(403, $this->send('PUT', $this->path($uri), ['Authorization' => $bob], 'x')->getStatusCode());
    $this->assertSame(403, $this->send('DELETE', $this->path($uri), ['Authorization' => $bob])->getStatusCode());
    $this->assertSame(403, $this->send('GET', $this->path($linkset), ['Authorization' => $bob])->getStatusCode());
    $this->agent = NULL;
    $this->assertSame(401, $this->send('POST', '/lws/alice/root/', ['Content-Type' => 'text/plain'], 'x')->getStatusCode());
    $this->assertSame(401, $this->send('DELETE', $this->path($uri))->getStatusCode());
    $this->assertSame(401, $this->send('GET', $this->path($linkset))->getStatusCode());
    $this->agent = 'https://id.example/alice';
    $this->assertCount(1, $this->listing(self::ROOT)->items);
  }

  /**
   * Tests that content is never served through core's private file route.
   */
  public function testDirectDownloadRefused(): void {
    $uri = $this->post('/lws/alice/root/', 'secret', ['Slug' => 's.txt']);
    $fileUri = (string) $this->resource($uri)?->getContentFile()?->getFileUri();
    $this->assertStringStartsWith('private://lws/', $fileUri);
    $this->assertContains(-1, $this->container->get('module_handler')->invokeAll('file_download', [$fileUri]));
  }

  /**
   * Tests that deleting a storage leaves its files to the queue.
   */
  public function testDeleteStorage(): void {
    $uri = $this->post('/lws/alice/root/', 'x', ['Slug' => 'x.txt']);
    $fileUri = (string) $this->resource($uri)?->getContentFile()?->getFileUri();
    $this->loadStorage('alice')->delete();
    $this->container->get('cron')->run();
    $this->assertFileDoesNotExist($fileUri);
  }

  /**
   * Tests sweeping content that nothing refers to.
   */
  public function testSweep(): void {
    $uri = $this->post('/lws/alice/root/', 'kept', ['Slug' => 'k.txt']);
    $kept = (string) $this->resource($uri)?->getContentFile()?->getFileUri();
    // Bytes a crashed write left behind, an hour ago, and one in progress.
    $fileSystem = $this->container->get('file_system');
    $directory = 'private://lws/stray';
    $fileSystem->prepareDirectory($directory, 1);
    file_put_contents($old = $directory . '/old', 'x');
    touch($old, time() - 7200);
    file_put_contents($recent = $directory . '/recent', 'x');
    // A file entity nothing uses any more.
    $files = $this->container->get('entity_type.manager')->getStorage('file');
    $unused = $files->create(['uri' => $directory . '/unused', 'status' => 1]);
    file_put_contents($directory . '/unused', 'x');
    $unused->save();

    $this->assertSame(['files' => 1, 'bytes' => 1], $this->container->get('lws_storage.content_sweeper')->sweep());
    $this->assertFileExists($kept);
    $this->assertFileExists($recent);
    $this->assertFileDoesNotExist($old);
    $this->assertFileDoesNotExist($directory . '/unused');
  }

}
