<?php

declare(strict_types=1);

namespace Drupal\lws_index;

use Drupal\lws\Access\AgentAccessScopeInterface;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\Listing\ContainerPager;
use Drupal\lws_storage\ResourceLinks;
use Drupal\lws_storage\ResourceRepository;
use Ebremer\Lws\LinkRelation;

/**
 * Lists the distinct types of the resources an agent may read (lws10-index).
 *
 * An agent who may read the whole storage gets plain queries, and an exact
 * count. For anyone else, each type is listed once a resource that bears it
 * is found that the agent may read, at the time of the request; a type borne
 * only by resources it may not read is never listed. Types are checked in
 * order within a scan limit of resources per page: a page that reaches it
 * ends there, and its next page goes on from that type and resource. The
 * count is of the types found within the limit from the start, never more
 * than the truth.
 */
final class TypeIndex {

  /**
   * Where the first page starts.
   *
   * @var array{a: string|null, t: string|null, r: int}
   */
  public const FIRST = ['a' => NULL, 't' => NULL, 'r' => 0];

  public function __construct(
    private readonly IndexQueries $queries,
    private readonly ResourceLinks $links,
    private readonly ResourceRepository $resources,
    private readonly ContainerPager $pager,
  ) {}

  /**
   * One page of the types an agent may see.
   *
   * @param \Drupal\lws_storage\Entity\LwsStorageInterface $storage
   *   The storage.
   * @param array{a: string|null, t: string|null, r: int} $from
   *   Where the page starts, as TypePage::$next gives it.
   * @param \Drupal\lws\Access\AgentAccessScopeInterface $scope
   *   What the agent may read.
   */
  public function page(LwsStorageInterface $storage, array $from, AgentAccessScopeInterface $scope): TypePage {
    $size = $this->pager->pageSize($storage);
    $storageId = (int) $storage->id();
    if (!$scope->readsSubtree($this->links->contextOf($storage, $this->resources->root($storage)))) {
      $region = $this->queries->region($storage, $scope->readableTargets());
      if (IndexQueries::isEmpty($region)) {
        return new TypePage([], 0, NULL);
      }
      $limit = IndexQueries::scanLimit();
      [$types, $next] = $this->visibleTypes($storage, $region, $from, $size, max($limit, $size), $scope);
      [$counted] = $this->visibleTypes($storage, $region, self::FIRST, PHP_INT_MAX, $limit, $scope);
      return new TypePage($types, count($counted), $next);
    }
    $types = array_values(array_map('strval', $this->queries->types($storageId, NULL, $from['a'])->range(0, $size + 1)->execute()?->fetchCol() ?? []));
    $next = NULL;
    if (count($types) > $size) {
      $types = array_slice($types, 0, $size);
      $next = ['a' => $types[$size - 1], 't' => NULL, 'r' => 0];
    }
    return new TypePage($types, IndexQueries::count($this->queries->types($storageId, NULL, NULL)), $next);
  }

  /**
   * The types an agent may see, checked in order within a budget.
   *
   * @param \Drupal\lws_storage\Entity\LwsStorageInterface $storage
   *   The storage.
   * @param array{exact: list<string>, prefixes: list<string>}|null $region
   *   Where the agent may read anything; NULL for anywhere.
   * @param array{a: string|null, t: string|null, r: int} $from
   *   Where to start.
   * @param int $size
   *   The most types to return.
   * @param int $budget
   *   The most resources to examine.
   * @param \Drupal\lws\Access\AgentAccessScopeInterface $scope
   *   What the agent may read.
   *
   * @return array{0: list<string>, 1: array{a: string|null, t: string|null, r: int}|null}
   *   The types, and where the next page starts; NULL when none does.
   */
  private function visibleTypes(LwsStorageInterface $storage, ?array $region, array $from, int $size, int $budget, AgentAccessScopeInterface $scope): array {
    $storageId = (int) $storage->id();
    $visible = [];
    // The last type known to be listed or not.
    $decided = $from['a'];
    $after = $from['a'];
    while (TRUE) {
      $types = array_values(array_map('strval', $this->queries->types($storageId, $region, $after)->range(0, IndexQueries::BATCH)->execute()?->fetchCol() ?? []));
      foreach ($types as $type) {
        $start = $type === $from['t'] ? $from['r'] : 0;
        [$found, $position, $complete] = $this->firstReadable($storage, $region, $type, $start, $budget, $scope);
        if ($found) {
          // One more than fits: the next page starts with it.
          if (count($visible) === $size) {
            return [$visible, ['a' => $decided, 't' => NULL, 'r' => 0]];
          }
          $visible[] = $type;
        }
        elseif (!$complete) {
          return [$visible, ['a' => $decided, 't' => $type, 'r' => $position]];
        }
        $decided = $type;
      }
      if (count($types) < IndexQueries::BATCH) {
        return [$visible, NULL];
      }
      $after = $types[count($types) - 1];
    }
  }

  /**
   * Looks for a resource of a type that the agent may read.
   *
   * @param \Drupal\lws_storage\Entity\LwsStorageInterface $storage
   *   The storage.
   * @param array{exact: list<string>, prefixes: list<string>}|null $region
   *   Where to look.
   * @param string $type
   *   The type.
   * @param int $after
   *   The ID of the resource to start after.
   * @param int $budget
   *   The resources it may still examine, reduced by those it does.
   * @param \Drupal\lws\Access\AgentAccessScopeInterface $scope
   *   What the agent may read.
   *
   * @return array{0: bool, 1: int, 2: bool}
   *   Whether one was found; the ID of the last resource examined; and
   *   whether the search is over: it is not when the budget ran out first.
   */
  private function firstReadable(LwsStorageInterface $storage, ?array $region, string $type, int $after, int &$budget, AgentAccessScopeInterface $scope): array {
    $hashes = [[Indexer::hash(LinkRelation::TYPE, $type)]];
    $position = $after;
    while ($budget > 0) {
      $wanted = min(IndexQueries::BATCH, $budget);
      [$ids, $batch] = $this->queries->fetch($this->queries->resources((int) $storage->id(), $hashes, $region, $position), $wanted);
      $contexts = $this->links->contextsOf($storage, $batch);
      foreach ($batch as $i => $resource) {
        $budget--;
        if ($scope->mayRead($contexts[$i])) {
          return [TRUE, (int) $resource->id(), TRUE];
        }
      }
      if (count($ids) < $wanted) {
        return [FALSE, $position, TRUE];
      }
      $position = $ids[count($ids) - 1];
    }
    return [FALSE, $position, FALSE];
  }

}
