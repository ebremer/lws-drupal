<?php

declare(strict_types=1);

namespace Drupal\lws_index;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws_storage\Entity\LwsResourceInterface;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\Listing\ContainerPager;
use Ebremer\Lws\LinkRelation;

/**
 * The queries of type searches and the type index.
 *
 * Resources are found in the order they were created, and types in code
 * point order. An agent's reach, from AgentAccessScopeInterface::
 * readableTargets(), can narrow a query to the resources and subtrees it may
 * read; that only saves work, since the agent's access is still checked for
 * each resource found.
 */
final class IndexQueries {

  /**
   * The resources fetched and checked at once.
   */
  public const BATCH = 100;

  /**
   * The most targets a query is narrowed to; beyond that, it is not.
   */
  private const MAX_TARGETS = 100;

  /**
   * A hash no index entry has, for a group that can match nothing.
   */
  public const NOTHING = '-';

  public function __construct(
    private readonly Connection $database,
    private readonly LwsUrlGenerator $urls,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * The resources a filtered page or count examines at most.
   *
   * As for container listings: $settings['lws_storage_scan_limit'].
   */
  public static function scanLimit(): int {
    return max(1, (int) Settings::get('lws_storage_scan_limit', ContainerPager::SCAN_LIMIT));
  }

  /**
   * Where in a storage an agent may read anything, as resource paths.
   *
   * @param \Drupal\lws_storage\Entity\LwsStorageInterface $storage
   *   The storage.
   * @param list<string>|null $targets
   *   The URIs from AgentAccessScopeInterface::readableTargets().
   *
   * @return array{exact: list<string>, prefixes: list<string>}|null
   *   The path hashes of resources, and the paths of containers whose
   *   subtrees are in reach; NULL for anywhere.
   */
  public function region(LwsStorageInterface $storage, ?array $targets): ?array {
    if ($targets === NULL || count($targets) > self::MAX_TARGETS) {
      return NULL;
    }
    $base = $this->urls->storageUri($storage->getSlug());
    $exact = [];
    $prefixes = [];
    foreach ($targets as $uri) {
      if ($uri === $base) {
        return NULL;
      }
      // Nothing else in the storage can be read, or found.
      if (!str_starts_with($uri, $base . 'root/')) {
        continue;
      }
      $relative = substr($uri, strlen($base));
      $container = str_ends_with($relative, '/');
      $segments = explode('/', $container ? substr($relative, 0, -1) : $relative);
      $path = implode('/', array_map('rawurldecode', $segments));
      if ($container) {
        $prefixes[] = $path . '/';
      }
      else {
        $exact[] = hash('sha256', $path);
      }
    }
    return ['exact' => array_values(array_unique($exact)), 'prefixes' => array_values(array_unique($prefixes))];
  }

  /**
   * Whether a region holds nothing at all.
   *
   * @param array{exact: list<string>, prefixes: list<string>}|null $region
   *   The region.
   */
  public static function isEmpty(?array $region): bool {
    return $region !== NULL && $region['exact'] === [] && $region['prefixes'] === [];
  }

  /**
   * The resources that match a filter, as IDs.
   *
   * @param int $storageId
   *   The storage ID.
   * @param list<list<string>> $hashes
   *   For each group of the filter, the index hashes of which a resource
   *   must hold one; no group matches every resource.
   * @param array{exact: list<string>, prefixes: list<string>}|null $region
   *   Where to look; NULL for anywhere.
   * @param int $after
   *   The ID the resources come after.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The query, of "id", in order.
   */
  public function resources(int $storageId, array $hashes, ?array $region, int $after): SelectInterface {
    if ($hashes === []) {
      $query = $this->database->select('lws_resource', 'r');
      $query->addField('r', 'id', 'id');
      $query->condition('r.storage', $storageId);
      $query->condition('r.id', $after, '>');
    }
    else {
      // The first group drives; each other group must hold too.
      $query = $this->database->select(Indexer::TABLE, 'l0');
      $query->addField('l0', 'resource_id', 'id');
      $query->condition('l0.storage_id', $storageId);
      $query->condition('l0.hash', $hashes[0], 'IN');
      $query->condition('l0.resource_id', $after, '>');
      foreach (array_slice($hashes, 1) as $i => $group) {
        $alias = 'l' . ($i + 1);
        $holds = $this->database->select(Indexer::TABLE, $alias);
        $holds->addExpression('1');
        $holds->where("$alias.resource_id = l0.resource_id");
        $holds->condition("$alias.hash", $group, 'IN');
        $query->exists($holds);
      }
      // A resource can match the first group more than once.
      $query->distinct();
      if ($region !== NULL) {
        $query->join('lws_resource', 'r', 'r.id = l0.resource_id');
      }
    }
    if ($region !== NULL) {
      $this->restrict($query, $region);
    }
    $query->orderBy('id');
    return $query;
  }

  /**
   * The distinct types of a storage's resources.
   *
   * @param int $storageId
   *   The storage ID.
   * @param array{exact: list<string>, prefixes: list<string>}|null $region
   *   Where to look; NULL for anywhere.
   * @param string|null $after
   *   The type they come after.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The query, of "type", in order.
   */
  public function types(int $storageId, ?array $region, ?string $after): SelectInterface {
    $query = $this->database->select(Indexer::TABLE, 'l');
    $query->addField('l', 'href', 'type');
    $query->condition('l.storage_id', $storageId);
    $query->condition('l.rel', LinkRelation::TYPE);
    if ($after !== NULL) {
      $query->condition('l.href', $after, '>');
    }
    if ($region !== NULL) {
      $query->join('lws_resource', 'r', 'r.id = l.resource_id');
      $this->restrict($query, $region);
    }
    $query->distinct();
    $query->orderBy('type');
    return $query;
  }

  /**
   * Fetches the next resources of a query of resources().
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   The query.
   * @param int $limit
   *   The most to fetch.
   *
   * @return array{0: list<int>, 1: list<\Drupal\lws_storage\Entity\LwsResourceInterface>}
   *   The IDs found, and the resources loaded, in order: a resource deleted
   *   meanwhile is not.
   */
  public function fetch(SelectInterface $query, int $limit): array {
    $ids = array_values(array_map('intval', $query->range(0, $limit)->execute()?->fetchCol() ?? []));
    return [$ids, $this->load($ids)];
  }

  /**
   * Loads resources, in the order of their IDs.
   *
   * @param list<int> $ids
   *   The IDs.
   *
   * @return list<\Drupal\lws_storage\Entity\LwsResourceInterface>
   *   The resources that exist.
   */
  public function load(array $ids): array {
    if ($ids === []) {
      return [];
    }
    $loaded = $this->entityTypeManager->getStorage('lws_resource')->loadMultiple($ids);
    $resources = [];
    foreach ($ids as $id) {
      if (($loaded[$id] ?? NULL) instanceof LwsResourceInterface) {
        $resources[] = $loaded[$id];
      }
    }
    return $resources;
  }

  /**
   * Counts what a query finds.
   */
  public static function count(SelectInterface $query): int {
    return (int) $query->countQuery()->execute()?->fetchField();
  }

  /**
   * Restricts a query joined to lws_resource as "r" to a region.
   *
   * LIKE may ignore case, as on PostgreSQL: the region is then larger,
   * which is harmless.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   The query.
   * @param array{exact: list<string>, prefixes: list<string>} $region
   *   The region; not empty.
   */
  private function restrict(SelectInterface $query, array $region): void {
    $within = $query->orConditionGroup();
    if ($region['exact'] !== []) {
      $within->condition('r.path_hash', $region['exact'], 'IN');
    }
    foreach ($region['prefixes'] as $prefix) {
      $within->condition('r.path', $this->database->escapeLike($prefix) . '%', 'LIKE');
    }
    $query->condition($within);
  }

}
