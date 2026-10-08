<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_storage\Kernel;

use Drupal\lws_storage_test\Access\PartialReader;
use Ebremer\Lws\Http\Headers;
use Ebremer\Lws\Model\ContainerPage;
use Ebremer\Lws\Model\ResourceMetadata;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests paginated container listings (LWS Core §12.1.2).
 */
#[Group('lws')]
#[RunTestsInSeparateProcesses]
final class PaginationTest extends LwsStorageKernelTestBase {

  private const ROOT = self::BASE . '/lws/alice/root/';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['lws_storage_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->storages->createStorage('alice', 'Alice', ['https://id.example/alice'], NULL, NULL, NULL, 5);
    $this->agent = 'https://id.example/alice';
  }

  /**
   * Creates data resources in the root, named as given.
   */
  private function create(string ...$names): void {
    foreach ($names as $name) {
      $response = $this->send('POST', '/lws/alice/root/', ['Content-Type' => 'text/plain', 'Slug' => $name], $name);
      $this->assertSame(201, $response->getStatusCode());
    }
  }

  /**
   * Reads a page and parses it with the PHP LWS client.
   *
   * @param string $uri
   *   The page URI.
   * @param array<string, string> $headers
   *   Request headers.
   */
  private function page(string $uri, array $headers = []): ContainerPage {
    $response = $this->get($uri, $headers);
    $this->assertSame(200, $response->getStatusCode(), $uri);
    return ContainerPage::parse($this->json($response), new ResourceMetadata($uri, 200, new Headers($response->headers->all())));
  }

  /**
   * Sends a GET to an absolute URI on the test site.
   *
   * @param string $uri
   *   The URI.
   * @param array<string, string> $headers
   *   Request headers.
   */
  private function get(string $uri, array $headers = []): Response {
    return $this->send('GET', substr($uri, strlen(self::BASE)), $headers);
  }

  /**
   * The names of a page's members.
   *
   * @return list<string>
   *   The names.
   */
  private static function names(ContainerPage $page): array {
    return array_map(static fn ($item): string => basename($item->id), $page->items);
  }

  /**
   * Tests walking eight members across two pages of five.
   */
  public function testTwoPages(): void {
    $this->create('a', 'b', 'c', 'd', 'e', 'f', 'g', 'h');
    $first = $this->page(self::ROOT);
    $this->assertSame(['a', 'b', 'c', 'd', 'e'], self::names($first));
    $this->assertSame(8, $first->totalItems);
    $this->assertSame(self::ROOT, $first->id);
    $this->assertSame(self::ROOT, $first->first);
    $this->assertNull($first->prev);
    $this->assertNotNull($first->next);
    $this->assertStringStartsWith(self::ROOT . '?page=', (string) $first->next);
    $this->assertSame($first->next, $first->last);

    $second = $this->page((string) $first->next);
    $this->assertSame(['f', 'g', 'h'], self::names($second));
    $this->assertSame(8, $second->totalItems);
    $this->assertSame(self::ROOT, $second->id);
    $this->assertSame(self::ROOT, $second->first);
    $this->assertSame(self::ROOT, $second->prev);
    $this->assertNull($second->next);
    $this->assertSame($first->next, $second->last);

    // The first page keeps the container's entity tag; others have their
    // own, and each answers conditional requests.
    $this->assertSame('"c9"', $first->etag);
    $this->assertNotSame($first->etag, $second->etag);
    $this->assertSame(304, $this->get((string) $first->next, ['If-None-Match' => (string) $second->etag])->getStatusCode());
  }

  /**
   * Tests a container that fits on one page.
   */
  public function testSinglePage(): void {
    $this->create('a', 'b', 'c');
    $page = $this->page(self::ROOT);
    $this->assertSame(['a', 'b', 'c'], self::names($page));
    $this->assertSame(self::ROOT, $page->first);
    $this->assertSame(self::ROOT, $page->last);
    $this->assertNull($page->next);
    $this->assertNull($page->prev);
  }

  /**
   * Tests the links of a middle page, and the last page's start.
   */
  public function testMiddlePage(): void {
    $this->create(...range('a', 'l'));
    $first = $this->page(self::ROOT);
    $middle = $this->page((string) $first->next);
    $this->assertSame(['f', 'g', 'h', 'i', 'j'], self::names($middle));
    $this->assertSame(self::ROOT, $middle->prev);
    $last = $this->page((string) $middle->last);
    $this->assertSame(['k', 'l'], self::names($last));
    $this->assertSame($middle->next, $middle->last);
    $this->assertSame($middle->last, $last->last);
    // From the last page back.
    $back = $this->page((string) $last->prev);
    $this->assertSame(['f', 'g', 'h', 'i', 'j'], self::names($back));
  }

  /**
   * Tests that pages stay consistent while members come and go.
   */
  public function testChangesBetweenPages(): void {
    $this->create('b', 'd', 'f', 'h', 'j', 'l', 'n');
    $first = $this->page(self::ROOT);
    $this->assertSame(['b', 'd', 'f', 'h', 'j'], self::names($first));
    // A member before the cursor and one after it arrive, and one of the
    // first page goes.
    $this->create('a', 'm');
    $this->assertSame(204, $this->send('DELETE', '/lws/alice/root/d')->getStatusCode());
    $second = $this->page((string) $first->next);
    $this->assertSame(['l', 'm', 'n'], self::names($second));
    $this->assertSame(8, $second->totalItems);
  }

  /**
   * Tests cursors that are not the server's.
   */
  public function testInvalidCursors(): void {
    $this->create(...range('a', 'g'));
    $next = (string) $this->page(self::ROOT)->next;
    [, $cursor] = explode('?page=', $next);
    [$payload, $signature] = explode('.', $cursor);

    $forged = rtrim(strtr(base64_encode('{"a":"b"}'), '+/', '-_'), '=') . '.' . $signature;
    foreach (['', 'abc', $payload, $forged, $cursor . 'x'] as $bad) {
      $this->assertProblem($this->send('GET', '/lws/alice/root/?page=' . $bad), 404, self::ROOT);
    }

    // A cursor of another container, such as one deleted and created again
    // at the same URI, is stale.
    $container = ['Link' => '<https://www.w3.org/ns/lws#Container>; rel="type"', 'Slug' => 'sub'];
    $this->send('POST', '/lws/alice/root/', $container);
    $this->assertProblem($this->send('GET', '/lws/alice/root/sub/?page=' . $cursor), 404, self::ROOT . 'sub/');
  }

  /**
   * Tests the site's default page size.
   */
  public function testDefaultPageSize(): void {
    $this->storages->createStorage('bob', 'Bob', ['https://id.example/alice']);
    $this->config('lws_storage.settings')->set('page_size', 2)->save();
    $authorization = 'Bearer ' . $this->token('https://id.example/alice', ['aud' => self::BASE . '/lws/bob/']);
    foreach (['x', 'y', 'z'] as $name) {
      $this->send('POST', '/lws/bob/root/', ['Slug' => $name, 'Authorization' => $authorization], $name);
    }
    $response = $this->send('GET', '/lws/bob/root/', ['Authorization' => $authorization]);
    $this->assertCount(2, $this->json($response)['items']);
    $this->assertSame(3, $this->json($response)['totalItems']);
  }

  /**
   * Tests listings filtered member by member for an agent.
   */
  public function testFilteredListing(): void {
    $this->create('a', 'secret-b', 'c', 'secret-d', 'e', 'f', 'g', 'secret-h', 'i');
    $this->agent = PartialReader::READER;
    $first = $this->page(self::ROOT);
    $this->assertSame(['a', 'c', 'e', 'f', 'g'], self::names($first));
    $this->assertSame(6, $first->totalItems);
    $this->assertNotNull($first->next);
    $second = $this->page((string) $first->next);
    $this->assertSame(['i'], self::names($second));
    $this->assertSame(6, $second->totalItems);
    $this->assertNull($second->next);
    // The reader cannot read what is hidden from the listing.
    $this->assertSame(403, $this->send('GET', '/lws/alice/root/secret-b')->getStatusCode());
  }

}
