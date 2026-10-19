<?php

declare(strict_types=1);

namespace Drupal\lws_storage;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lws\Routing\LwsArea;
use Drupal\lws\Routing\LwsTarget;
use Drupal\lws_storage\Entity\LwsResourceInterface;
use Drupal\lws_storage\Entity\LwsStorageInterface;

/**
 * Moving resources between containers (LWS Core, Moving Resources).
 *
 * Clients move a resource by changing the "up" link of its linkset, which
 * lws_storage.settings:move_applies_to allows for data resources,
 * containers, both or neither. Every URI here is derived from the
 * containment hierarchy, so a moved resource takes a new one: its new
 * container's URI and its name. The storage description says so with
 * "preservesIdentifier": false.
 *
 * Where a resource was is recorded, by its former path, so that its former
 * URIs answer 301 Moved Permanently with its new one (find()). A record of a
 * moved container covers the paths below its former one too, as long as
 * nothing exists there: a resource created at a former path, or in a
 * container created there, takes precedence.
 */
final class ResourceMoves {

  /**
   * The table of former paths.
   */
  public const TABLE = 'lws_resource_move';

  /**
   * The kinds of resource that may move, as "appliesTo" names them.
   */
  public const KINDS = ['DataResource', 'Container'];

  /**
   * The most moves find() follows from a former path.
   *
   * A resource that moved out of a container after the container moved is
   * found through both moves.
   */
  private const MAX_HOPS = 10;

  /**
   * The most paths one query looks up at once.
   */
  private const PATHS_PER_QUERY = 500;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ResourceRepository $resources,
  ) {}

  /**
   * The kinds of resource clients may move; none when moving is off.
   *
   * @return list<string>
   *   "DataResource", "Container", both or neither, in that order.
   */
  public function appliesTo(): array {
    $kinds = $this->configFactory->get('lws_storage.settings')->get('move_applies_to');
    return array_values(array_intersect(self::KINDS, is_array($kinds) ? $kinds : []));
  }

  /**
   * Whether clients may move a resource of this kind.
   */
  public function allows(LwsResourceInterface $resource): bool {
    return in_array($resource->isContainer() ? 'Container' : 'DataResource', $this->appliesTo(), TRUE);
  }

  /**
   * Records where a resource was, inside the transaction that moves it.
   *
   * A later move from the same path replaces the record.
   *
   * @param \Drupal\lws_storage\Entity\LwsResourceInterface $moved
   *   The resource, moved.
   * @param string $path
   *   Its former path.
   */
  public function record(LwsResourceInterface $moved, string $path): void {
    $this->database->merge(self::TABLE)
      ->keys([
        'storage_id' => $moved->getLwsStorageId(),
        'path_hash' => hash('sha256', $path),
      ])
      ->fields([
        'path' => $path,
        'resource_id' => (int) $moved->id(),
      ])
      ->execute();
  }

  /**
   * The resource that was at a path where nothing exists, as it is now.
   *
   * The record of the path, or else of the nearest container above it where
   * nothing exists either, names the resource that moved from there; the
   * path's remainder below that is then looked for below the resource's
   * current path, and so on, should that have moved too.
   *
   * @param \Drupal\lws_storage\Entity\LwsStorageInterface $storage
   *   The storage.
   * @param string $path
   *   The decoded path, such as "root/notes/a.txt".
   *
   * @return \Drupal\lws_storage\Entity\LwsResourceInterface|null
   *   The resource; NULL when none moved from there, or it no longer exists.
   */
  public function find(LwsStorageInterface $storage, string $path): ?LwsResourceInterface {
    $seen = [];
    for ($hop = 0; $hop < self::MAX_HOPS && !isset($seen[$path]); $hop++) {
      $seen[$path] = TRUE;
      $record = $this->nearest($storage, $path);
      if ($record === NULL) {
        return NULL;
      }
      [$former, $id] = $record;
      $moved = $this->entityTypeManager->getStorage('lws_resource')->load($id);
      if (!$moved instanceof LwsResourceInterface || $moved->getLwsStorageId() !== (int) $storage->id()) {
        return NULL;
      }
      $path = $moved->getPath() . substr($path, strlen($former));
      $found = $this->resources->findByPath($storage, $path);
      if ($found !== NULL) {
        return $found;
      }
    }
    return NULL;
  }

  /**
   * The resource that was at a URL where nothing exists, as it is now.
   *
   * As find(), for a request's target.
   */
  public function findByTarget(LwsStorageInterface $storage, LwsTarget $target): ?LwsResourceInterface {
    if ($target->area !== LwsArea::Resource) {
      return NULL;
    }
    return $this->find($storage, implode('/', $target->segments) . ($target->container ? '/' : ''));
  }

  /**
   * Forgets the former paths of a resource, as when it is deleted.
   */
  public function forget(int $resourceId): void {
    $this->database->delete(self::TABLE)->condition('resource_id', $resourceId)->execute();
  }

  /**
   * Forgets the former paths of a storage's resources, as when it is deleted.
   */
  public function forgetStorage(int $storageId): void {
    $this->database->delete(self::TABLE)->condition('storage_id', $storageId)->execute();
  }

  /**
   * The record that applies to a path, if one does.
   *
   * That of the path itself, or of the nearest container above it, provided
   * nothing exists there or anywhere between: a resource that does shadows
   * the records at and above its own path.
   *
   * @return array{string, int}|null
   *   The recorded path and the ID of the resource that moved from it.
   */
  private function nearest(LwsStorageInterface $storage, string $path): ?array {
    $levels = self::levels($path);
    $records = [];
    foreach ($this->rows(self::TABLE, 'storage_id', (int) $storage->id(), $levels, ['path', 'resource_id']) as $row) {
      $records[(string) $row['path']] = (int) $row['resource_id'];
    }
    if ($records === []) {
      return NULL;
    }
    // Only the levels down to the farthest one recorded can shadow a record.
    $farthest = max(array_map(static fn (string $recorded): int => (int) array_search($recorded, $levels, TRUE), array_keys($records)));
    $existing = [];
    foreach ($this->rows('lws_resource', 'storage', (int) $storage->id(), array_slice($levels, 0, $farthest + 1), ['path']) as $row) {
      $existing[(string) $row['path']] = TRUE;
    }
    foreach ($levels as $level) {
      if (isset($existing[$level])) {
        return NULL;
      }
      if (isset($records[$level])) {
        return [$level, $records[$level]];
      }
    }
    return NULL;
  }

  /**
   * The rows of a table of paths whose paths are among some.
   *
   * Looked up by the paths' hashes; the paths are compared too, as the
   * database may compare case-insensitively.
   *
   * @param string $table
   *   The table, with "path" and "path_hash" columns.
   * @param string $storageColumn
   *   Its column of the storage ID.
   * @param int $storageId
   *   The storage.
   * @param list<string> $paths
   *   The paths.
   * @param list<string> $fields
   *   The columns to read; "path" among them.
   *
   * @return list<array<string, mixed>>
   *   The rows.
   */
  private function rows(string $table, string $storageColumn, int $storageId, array $paths, array $fields): array {
    $rows = [];
    foreach (array_chunk($paths, self::PATHS_PER_QUERY) as $chunk) {
      $result = $this->database->select($table, 't')
        ->fields('t', $fields)
        ->condition($storageColumn, $storageId)
        ->condition('path_hash', array_map(static fn (string $path): string => hash('sha256', $path), $chunk), 'IN')
        ->execute();
      foreach ($result ?? [] as $row) {
        $row = (array) $row;
        if (in_array((string) ($row['path'] ?? ''), $chunk, TRUE)) {
          $rows[] = $row;
        }
      }
    }
    return $rows;
  }

  /**
   * A path and the paths of the containers above it, nearest first.
   *
   * The root container's is left out: it never moves.
   *
   * @return non-empty-list<string>
   *   The paths.
   */
  private static function levels(string $path): array {
    $levels = [$path];
    $segments = explode('/', rtrim($path, '/'));
    for ($i = count($segments) - 1; $i > 1; $i--) {
      $levels[] = implode('/', array_slice($segments, 0, $i)) . '/';
    }
    return $levels;
  }

}
