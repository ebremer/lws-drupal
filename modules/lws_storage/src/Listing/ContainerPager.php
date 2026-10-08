<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Listing;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\lws\Access\AgentAccessScopeInterface;
use Drupal\lws\Http\LwsHttpException;
use Drupal\lws\Http\PaginationCursor;
use Drupal\lws_storage\Entity\LwsResourceInterface;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\ResourceLinks;
use Drupal\lws_storage\ResourceRepository;

/**
 * Pages through container listings (LWS Core §8.1, DESIGN.md §5.6).
 *
 * Members are listed in name order, and a page starts after the last name of
 * the one before, so pages stay consistent while members come and go. When
 * the agent may read everything below the container, a page is a plain query
 * and every link (next, prev, last) is offered. Otherwise members are checked
 * one by one, the count is of the members the agent may see, and only next
 * is offered.
 */
final class ContainerPager {

  /**
   * The members fetched per query when they are checked one by one.
   */
  private const BATCH = 100;

  /**
   * The most members one page may hold, whatever a storage asks for.
   */
  public const MAX_PAGE_SIZE = 1000;

  /**
   * The members a filtered page, and its count, examine at most.
   *
   * $settings['lws_storage_scan_limit'] changes it.
   */
  public const SCAN_LIMIT = 1000;

  public function __construct(
    private readonly ResourceRepository $resources,
    private readonly ResourceLinks $links,
    private readonly PaginationCursor $cursors,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * The members on one page of a container's listing.
   *
   * @param \Drupal\lws_storage\Entity\LwsStorageInterface $storage
   *   The storage.
   * @param \Drupal\lws_storage\Entity\LwsResourceInterface $container
   *   The container.
   * @param string|null $cursor
   *   The cursor from the page URI; NULL for the first page.
   * @param \Drupal\lws\Access\AgentAccessScopeInterface $scope
   *   What the agent may read.
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   404 for a cursor that is malformed, altered or for another container.
   */
  public function page(LwsStorageInterface $storage, LwsResourceInterface $container, ?string $cursor, AgentAccessScopeInterface $scope): ContainerPage {
    $after = $cursor === NULL ? NULL : $this->after($container, $cursor);
    $size = $this->pageSize($storage);
    $page = $this->isFiltered($storage, $container, $scope)
      ? $this->checkedPage($storage, $container, $after, $size, $scope)
      : $this->plainPage($container, $after, $size);
    $this->preloadFiles($page->members);
    return $page;
  }

  /**
   * Whether an agent's listing of a container leaves members out.
   *
   * It does unless the agent may read everything below the container.
   */
  public function isFiltered(LwsStorageInterface $storage, LwsResourceInterface $container, AgentAccessScopeInterface $scope): bool {
    return !$scope->readsSubtree($this->links->contextOf($storage, $container));
  }

  /**
   * The key a cursor's page starts after.
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   404 for a cursor that does not decode for the container.
   */
  public function after(LwsResourceInterface $container, string $cursor): string {
    return $this->cursors->decode((string) $container->uuid(), $cursor)
      ?? throw LwsHttpException::notFound('This page of the listing does not exist.');
  }

  /**
   * The members on one page of a storage's listings.
   */
  public function pageSize(LwsStorageInterface $storage): int {
    $size = $storage->getPageSize() ?? (int) ($this->configFactory->get('lws_storage.settings')->get('page_size') ?: 100);
    return min(self::MAX_PAGE_SIZE, max(1, $size));
  }

  /**
   * A page made of plain queries, for an agent who may read every member.
   */
  private function plainPage(LwsResourceInterface $container, ?string $after, int $size): ContainerPage {
    $members = $this->resources->membersAfter($container, $after, $size + 1);
    $next = NULL;
    if (count($members) > $size) {
      $members = array_slice($members, 0, $size);
      $next = $this->cursor($container, $members[$size - 1]->getName());
    }
    $total = $this->resources->countMembers($container);

    // The page before holds the members just before this one's first.
    $prev = NULL;
    if ($after !== NULL) {
      $boundary = $members === [] ? NULL : $members[0]->getName();
      $before = $boundary === NULL ? [] : $this->resources->namesBefore($container, $boundary, $size + 1);
      $prev = count($before) > $size ? $this->cursor($container, $before[$size]) : '';
    }

    // Following next from the first page, the last one starts at a multiple
    // of the page size.
    $last = '';
    if ($total > $size) {
      $start = (intdiv($total - 1, $size)) * $size;
      $name = $this->resources->nameAt($container, $start - 1);
      $last = $name === NULL ? NULL : $this->cursor($container, $name);
    }
    return new ContainerPage($members, $total, $next, $prev, $last);
  }

  /**
   * A page of the members the agent may read, checked one by one.
   *
   * A page examines at most SCAN_LIMIT members, or one page's worth if that
   * is more: if the page is not full by then, it ends there, and the next
   * page goes on from the last member examined, which the encrypted cursor
   * does not reveal. totalItems counts the visible members among the first
   * SCAN_LIMIT, which is exact for most containers and never more than the
   * truth: an estimate would tell how many members the agent cannot see.
   * LWS Core §8.1 lets the count be approximate.
   *
   * Names are compared by the database, as when the members are ordered.
   */
  private function checkedPage(LwsStorageInterface $storage, LwsResourceInterface $container, ?string $after, int $size, AgentAccessScopeInterface $scope): ContainerPage {
    $visible = [];
    $next = NULL;
    $position = $after;
    $limit = max(1, (int) Settings::get('lws_storage_scan_limit', self::SCAN_LIMIT));
    $budget = max($limit, $size);
    while (TRUE) {
      $wanted = min(self::BATCH, $budget);
      $batch = $this->resources->membersAfter($container, $position, $wanted);
      $contexts = $this->links->contextsOf($storage, $batch);
      foreach ($batch as $i => $member) {
        $position = $member->getName();
        $budget--;
        if (!$scope->mayRead($contexts[$i])) {
          continue;
        }
        if (count($visible) === $size) {
          $next = $this->cursor($container, $visible[$size - 1]->getName());
          break 2;
        }
        $visible[] = $member;
      }
      if (count($batch) < $wanted) {
        break;
      }
      if ($budget <= 0) {
        $next = $this->cursor($container, count($visible) === $size ? $visible[$size - 1]->getName() : (string) $position);
        break;
      }
    }

    $total = 0;
    $seen = 0;
    $position = NULL;
    do {
      $wanted = min(self::BATCH, $limit - $seen);
      $batch = $this->resources->membersAfter($container, $position, $wanted);
      $contexts = $this->links->contextsOf($storage, $batch);
      foreach ($batch as $i => $member) {
        $position = $member->getName();
        $seen++;
        if ($scope->mayRead($contexts[$i])) {
          $total++;
        }
      }
    } while (count($batch) === $wanted && $seen < $limit);
    return new ContainerPage($visible, $total, $next, NULL, NULL, TRUE);
  }

  /**
   * A cursor for the page after a name.
   */
  private function cursor(LwsResourceInterface $container, string $after): string {
    return $this->cursors->encode((string) $container->uuid(), $after);
  }

  /**
   * Loads the files of data resources at once, for their format and size.
   *
   * @param list<\Drupal\lws_storage\Entity\LwsResourceInterface> $members
   *   The members.
   */
  private function preloadFiles(array $members): void {
    $fids = [];
    foreach ($members as $member) {
      $fid = (int) $member->get('content')->target_id;
      if ($fid > 0) {
        $fids[] = $fid;
      }
    }
    if ($fids !== []) {
      $this->entityTypeManager->getStorage('file')->loadMultiple($fids);
    }
  }

}
