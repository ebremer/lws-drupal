<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_index\Kernel;

use Drupal\lws_storage_test\Access\PartialReader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the pages of search results and of the type index.
 *
 * The storage's pages hold two items.
 */
#[Group('lws')]
#[RunTestsInSeparateProcesses]
final class IndexPagingTest extends IndexKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * PartialReader::READER may read everything but resources named
   * "secret…", and has no scope that narrows a search.
   */
  protected static $modules = ['lws_storage_test'];

  /**
   * {@inheritdoc}
   */
  protected ?int $pageSize = 2;

  /**
   * Follows the next links from a first page.
   *
   * @return array{0: list<list<string>>, 1: list<int>}
   *   The items of each page, and the totalItems each gave.
   */
  private function walk(?string $agent, Response $first, bool $types = FALSE): array {
    $pages = [];
    $totals = [];
    $response = $first;
    while (TRUE) {
      $pages[] = $types ? $this->listed($response) : $this->found($response);
      $totals[] = $this->json($response)['totalItems'];
      $next = $this->link($response, 'next');
      if ($next === NULL || count($pages) > 50) {
        return [$pages, $totals];
      }
      $this->assertStringStartsWith(self::STORAGE . 'types/' . ($types ? 'index' : 'search') . '?page=', $next);
      $response = $this->as($agent, 'GET', substr($next, strlen(self::BASE)));
    }
  }

  /**
   * Tests the pages of a search's results.
   */
  public function testSearchPages(): void {
    $names = ['a', 'b', 'secret-c', 'd', 'secret-e'];
    $paths = [];
    foreach ($names as $name) {
      $paths[$name] = $this->create('root/', $name, ['Alpha']);
    }
    $this->create('root/', 'f', ['Beta']);
    $alpha = ['type' => [self::T . 'Alpha']];

    // Alice reads everything: full pages, exact counts.
    $first = $this->search(self::ALICE, $alpha);
    [$pages, $totals] = $this->walk(self::ALICE, $first);
    $this->assertSame([[$paths['a'], $paths['b']], [$paths['secret-c'], $paths['d']], [$paths['secret-e']]], $pages);
    $this->assertSame([5, 5, 5], $totals);

    // The first page link answers the first page, with GET.
    $again = $this->as(self::ALICE, 'GET', substr((string) $this->link($first, 'first'), strlen(self::BASE)));
    $this->assertSame([$paths['a'], $paths['b']], $this->found($again));
    $this->assertSame($this->link($first, 'next'), $this->link($again, 'next'));

    // Each resource is checked for the reader: no secrets, and the count is
    // of what it may see.
    [$pages, $totals] = $this->walk(PartialReader::READER, $this->search(PartialReader::READER, $alpha));
    $this->assertSame([[$paths['a'], $paths['b']], [$paths['d']]], $pages);
    $this->assertSame([3, 3], $totals);

    // A page examines at most the scan limit; one that reaches it ends early,
    // and the next goes on from there.
    $this->setSetting('lws_storage_scan_limit', 2);
    [$pages, $totals] = $this->walk(PartialReader::READER, $this->search(PartialReader::READER, $alpha));
    $this->assertSame([$paths['a'], $paths['b'], $paths['d']], array_merge(...$pages));
    $this->assertSame(2, $totals[0]);

    // A page link is the storage's own.
    $this->storages->createStorage('other', 'Other', [self::ALICE]);
    $next = (string) $this->link($first, 'next');
    $elsewhere = str_replace('/lws/alice/', '/lws/other/', substr($next, strlen(self::BASE)));
    // (Without a token: Alice's tokens are for the storage "alice".)
    $this->assertSame(404, $this->as(NULL, 'GET', $elsewhere)->getStatusCode());
    $this->assertSame(404, $this->as(self::ALICE, 'GET', substr($next, strlen(self::BASE)) . 'x')->getStatusCode());
    $this->assertSame(404, $this->as(self::ALICE, 'GET', str_replace('types/search', 'types/index', substr($next, strlen(self::BASE))))->getStatusCode());

    // Following a link sees the resources as they are now.
    $this->assertSame(204, $this->as(self::ALICE, 'DELETE', '/lws/alice/' . $paths['secret-c'])->getStatusCode());
    $this->assertSame([$paths['d'], $paths['secret-e']], $this->found($this->as(self::ALICE, 'GET', substr($next, strlen(self::BASE)))));
  }

  /**
   * Tests the pages of the type index.
   */
  public function testTypePages(): void {
    foreach (['A', 'B', 'C', 'D', 'E'] as $type) {
      $this->create('root/', strtolower($type), [$type]);
    }
    // Types borne only by secrets are not the reader's to see, however many
    // of them it has to examine.
    foreach (range(1, 4) as $i) {
      $this->create('root/', 'secret-' . $i, ['C', 'Hidden']);
    }
    $all = [
      ...array_map(static fn (string $type): string => self::T . $type, ['A', 'B', 'C', 'D', 'E', 'Hidden']),
      self::LWS_CONTAINER,
      self::LWS_DATA,
    ];

    [$pages, $totals] = $this->walk(self::ALICE, $this->as(self::ALICE, 'GET', self::INDEX), TRUE);
    $this->assertSame(array_chunk($all, 2), $pages);
    $this->assertSame([8, 8, 8, 8], $totals);

    $visible = array_values(array_diff($all, [self::T . 'Hidden']));
    [$pages, $totals] = $this->walk(PartialReader::READER, $this->as(PartialReader::READER, 'GET', self::INDEX), TRUE);
    $this->assertSame(array_chunk($visible, 2), $pages);
    $this->assertSame(7, $totals[0]);

    // With a scan limit of 2, pages end where the limit is reached, and the
    // next goes on from the type and resource it reached: Hidden takes
    // several pages to rule out, and nothing is listed twice or left out.
    $this->setSetting('lws_storage_scan_limit', 2);
    [$pages, $totals] = $this->walk(PartialReader::READER, $this->as(PartialReader::READER, 'GET', self::INDEX), TRUE);
    $this->assertSame($visible, array_merge(...$pages));
    $this->assertGreaterThan(count(array_chunk($visible, 2)), count($pages));
    foreach ($totals as $total) {
      $this->assertLessThanOrEqual(7, $total);
    }
  }

}
