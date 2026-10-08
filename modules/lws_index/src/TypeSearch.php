<?php

declare(strict_types=1);

namespace Drupal\lws_index;

use Drupal\lws\Access\AgentAccessScopeInterface;
use Drupal\lws_index\Query\TypeFilter;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\Listing\ContainerPager;
use Drupal\lws_storage\ResourceLinks;
use Drupal\lws_storage\ResourceRepository;

/**
 * Finds the resources that match a type search filter (lws10-index).
 *
 * An agent who may read the whole storage gets plain queries, and exact
 * counts. Anyone else has each resource found checked, at the time of the
 * request, as filtered container listings do: a page examines at most a
 * scan limit of resources, and if it is not full by then, ends there, its
 * next page going on from the last resource examined; the count is of the
 * resources the agent may see among the first the limit lets it examine,
 * never more than the truth.
 */
final class TypeSearch {

  public function __construct(
    private readonly IndexQueries $queries,
    private readonly Relations $relations,
    private readonly ResourceLinks $links,
    private readonly ResourceRepository $resources,
    private readonly ContainerPager $pager,
  ) {}

  /**
   * One page of the resources that match a filter.
   *
   * @param \Drupal\lws_storage\Entity\LwsStorageInterface $storage
   *   The storage.
   * @param \Drupal\lws_index\Query\TypeFilter $filter
   *   The filter.
   * @param int $after
   *   The ID of the resource the page starts after; 0 for the first page.
   * @param \Drupal\lws\Access\AgentAccessScopeInterface $scope
   *   What the agent may read.
   */
  public function page(LwsStorageInterface $storage, TypeFilter $filter, int $after, AgentAccessScopeInterface $scope): ResultPage {
    $hashes = $this->hashes($filter);
    $size = $this->pager->pageSize($storage);
    $storageId = (int) $storage->id();
    if (!$scope->readsSubtree($this->links->contextOf($storage, $this->resources->root($storage)))) {
      return $this->checkedPage($storage, $hashes, $after, $size, $scope);
    }
    [$ids] = $this->queries->fetch($this->queries->resources($storageId, $hashes, NULL, $after), $size + 1);
    $next = NULL;
    if (count($ids) > $size) {
      $ids = array_slice($ids, 0, $size);
      $next = $ids[$size - 1];
    }
    $total = IndexQueries::count($this->queries->resources($storageId, $hashes, NULL, 0));
    return new ResultPage($this->queries->load($ids), $total, $next);
  }

  /**
   * The index hashes of a filter's groups.
   *
   * A group on a relation that is not searchable gets a hash no entry has,
   * so that the query runs as for a target nothing declares, and takes as
   * long.
   *
   * @return list<list<string>>
   *   For each group, the hashes of which a resource must hold one.
   */
  private function hashes(TypeFilter $filter): array {
    $hashes = $filter->hashes();
    foreach ($filter->groups as $i => $group) {
      if (!$this->relations->isSearchable($group['rel'])) {
        $hashes[$i] = [IndexQueries::NOTHING];
      }
    }
    return $hashes;
  }

  /**
   * A page of the matching resources the agent may read, checked one by one.
   *
   * @param \Drupal\lws_storage\Entity\LwsStorageInterface $storage
   *   The storage.
   * @param list<list<string>> $hashes
   *   The hashes of the filter's groups.
   * @param int $after
   *   The ID the page starts after.
   * @param int $size
   *   The page size.
   * @param \Drupal\lws\Access\AgentAccessScopeInterface $scope
   *   What the agent may read.
   */
  private function checkedPage(LwsStorageInterface $storage, array $hashes, int $after, int $size, AgentAccessScopeInterface $scope): ResultPage {
    $region = $this->queries->region($storage, $scope->readableTargets());
    if (IndexQueries::isEmpty($region)) {
      return new ResultPage([], 0, NULL);
    }
    $storageId = (int) $storage->id();
    $limit = IndexQueries::scanLimit();
    $visible = [];
    $next = NULL;
    $position = $after;
    $budget = max($limit, $size);
    while (TRUE) {
      $wanted = min(IndexQueries::BATCH, $budget);
      [$ids, $batch] = $this->queries->fetch($this->queries->resources($storageId, $hashes, $region, $position), $wanted);
      $contexts = $this->links->contextsOf($storage, $batch);
      foreach ($batch as $i => $resource) {
        $budget--;
        if (!$scope->mayRead($contexts[$i])) {
          continue;
        }
        if (count($visible) === $size) {
          $next = (int) $visible[$size - 1]->id();
          break 2;
        }
        $visible[] = $resource;
      }
      if (count($ids) < $wanted) {
        break;
      }
      $position = $ids[count($ids) - 1];
      if ($budget <= 0) {
        $next = count($visible) === $size ? (int) $visible[$size - 1]->id() : $position;
        break;
      }
    }

    $total = 0;
    $seen = 0;
    $position = 0;
    do {
      $wanted = min(IndexQueries::BATCH, $limit - $seen);
      [$ids, $batch] = $this->queries->fetch($this->queries->resources($storageId, $hashes, $region, $position), $wanted);
      $contexts = $this->links->contextsOf($storage, $batch);
      foreach ($contexts as $context) {
        if ($scope->mayRead($context)) {
          $total++;
        }
      }
      $seen += count($ids);
      $position = $ids === [] ? $position : $ids[count($ids) - 1];
    } while (count($ids) === $wanted && $seen < $limit);
    return new ResultPage($visible, $total, $next);
  }

}
