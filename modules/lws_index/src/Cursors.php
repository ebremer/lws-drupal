<?php

declare(strict_types=1);

namespace Drupal\lws_index;

use Drupal\lws\Http\PaginationCursor;
use Drupal\lws_index\Query\TypeFilter;
use Drupal\lws_storage\Entity\LwsStorageInterface;

/**
 * The page cursors of type searches and type indexes.
 *
 * A search is a QUERY whose filter is in its body, but the pages of its
 * results are read with GET: each page link carries the filter, compressed
 * and encrypted with the position (PaginationCursor), so the server keeps no
 * state. A cursor is bound to its storage and service, not to an agent:
 * whoever follows it sees only what they may read.
 */
final class Cursors {

  /**
   * The most bytes a cursor may inflate to.
   */
  private const MAX_INFLATED = 65536;

  public function __construct(
    private readonly PaginationCursor $cursors,
  ) {}

  /**
   * The cursor of a page of a search's results.
   *
   * @param \Drupal\lws_storage\Entity\LwsStorageInterface $storage
   *   The storage.
   * @param \Drupal\lws_index\Query\TypeFilter $filter
   *   The filter.
   * @param int $after
   *   The ID the page starts after; 0 for the first.
   */
  public function search(LwsStorageInterface $storage, TypeFilter $filter, int $after): string {
    return $this->encode(self::scope($storage, 'search'), ['f' => $filter->toArray(), 'a' => $after]);
  }

  /**
   * Decodes the cursor of a page of a search's results.
   *
   * @return array{0: \Drupal\lws_index\Query\TypeFilter, 1: int}|null
   *   The filter and the ID the page starts after; NULL if the cursor is not
   *   one.
   */
  public function decodeSearch(LwsStorageInterface $storage, string $cursor): ?array {
    $data = $this->decode(self::scope($storage, 'search'), $cursor);
    $filter = TypeFilter::fromArray($data['f'] ?? NULL);
    $after = $data['a'] ?? NULL;
    return $filter !== NULL && is_int($after) && $after >= 0 ? [$filter, $after] : NULL;
  }

  /**
   * The cursor of a page of the type index.
   *
   * @param \Drupal\lws_storage\Entity\LwsStorageInterface $storage
   *   The storage.
   * @param array{a: string|null, t: string|null, r: int} $from
   *   Where the page starts.
   */
  public function types(LwsStorageInterface $storage, array $from): string {
    return $this->encode(self::scope($storage, 'index'), $from);
  }

  /**
   * Decodes the cursor of a page of the type index.
   *
   * @return array{a: string|null, t: string|null, r: int}|null
   *   Where the page starts; NULL if the cursor is not one.
   */
  public function decodeTypes(LwsStorageInterface $storage, string $cursor): ?array {
    $data = $this->decode(self::scope($storage, 'index'), $cursor);
    if ($data === NULL) {
      return NULL;
    }
    $a = $data['a'] ?? NULL;
    $t = $data['t'] ?? NULL;
    $r = $data['r'] ?? NULL;
    if (($a !== NULL && !is_string($a)) || ($t !== NULL && !is_string($t)) || !is_int($r) || $r < 0) {
      return NULL;
    }
    return ['a' => $a, 't' => $t, 'r' => $r];
  }

  /**
   * What a cursor is for.
   */
  private static function scope(LwsStorageInterface $storage, string $service): string {
    return 'lws_index:' . $service . ':' . $storage->uuid();
  }

  /**
   * Encodes data as a cursor.
   *
   * @param string $scope
   *   What the cursor is for.
   * @param array<string, mixed> $data
   *   The data.
   */
  private function encode(string $scope, array $data): string {
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $deflated = gzdeflate($json, 9);
    if ($deflated === FALSE) {
      throw new \RuntimeException('A pagination cursor could not be compressed.');
    }
    return $this->cursors->encode($scope, $deflated);
  }

  /**
   * Decodes a cursor.
   *
   * @return array<string, mixed>|null
   *   The data; NULL if the cursor is malformed, altered, or for something
   *   else.
   */
  private function decode(string $scope, string $cursor): ?array {
    $deflated = $this->cursors->decode($scope, $cursor);
    $json = $deflated === NULL ? FALSE : @gzinflate($deflated, self::MAX_INFLATED);
    if (!is_string($json)) {
      return NULL;
    }
    try {
      $data = json_decode($json, TRUE, 16, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return NULL;
    }
    return is_array($data) && !array_is_list($data) ? $data : NULL;
  }

}
