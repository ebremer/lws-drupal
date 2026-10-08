<?php

declare(strict_types=1);

namespace Drupal\lws_notify;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\lws\Access\ResourceContext;
use Drupal\lws\Agent\RequestingAgent;
use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws\Storage\StorageRef;
use Drupal\lws_notify\Entity\LwsSubscriptionInterface;
use Ebremer\Lws\Vocabulary;

/**
 * Subscriptions to changes in storages (LWS Core §10.3).
 *
 * A subscription to a container covers it and everything in it, at any
 * depth; one to a data resource covers that resource only (§10.3.2). One
 * that has expired, or that a failing inbox deactivated, is no longer
 * delivered to, and is deleted a week later.
 */
final class Subscriptions {

  /**
   * How long an expired or deactivated subscription is kept, in seconds.
   *
   * Its subscriber can still see that it ended, and why.
   */
  public const KEEP_ENDED = 604800;

  /**
   * The live subscriptions of each storage, by storage ID, as last loaded.
   *
   * @var array<int, list<\Drupal\lws_notify\Entity\LwsSubscriptionInterface>>
   */
  private array $live = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LwsUrlGenerator $urls,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Stores a subscription.
   *
   * The caller has checked that the agent may read every topic, and that the
   * inbox may be delivered to.
   *
   * @throws \Drupal\lws_notify\SubscriptionLimitException
   *   When the agent holds as many subscriptions at the storage as it may.
   */
  public function create(StorageRef $storage, RequestingAgent $agent, SubscriptionRequest $request): LwsSubscriptionInterface {
    $limit = (int) $this->configFactory->get('lws_notify.settings')->get('limits.subscriptions_per_agent');
    if ($this->countLive($storage, (string) $agent->subject) >= $limit) {
      throw new SubscriptionLimitException(sprintf('An agent may hold %d subscriptions at a storage; cancel one first.', $limit));
    }
    $subscription = $this->storage()->create([
      'storage' => $storage->id,
      'type' => $request->type,
      'agent' => $agent->subject,
      'client' => $agent->client,
      'topic' => array_keys($request->topics),
      'inbox' => $request->inbox,
      'expires' => $request->expires,
    ]);
    assert($subscription instanceof LwsSubscriptionInterface);
    $subscription->save();
    $this->reset();
    return $subscription;
  }

  /**
   * A subscription of a storage, by UUID.
   */
  public function load(StorageRef $storage, string $uuid): ?LwsSubscriptionInterface {
    $subscriptions = $this->storage()->loadByProperties(['storage' => $storage->id, 'uuid' => $uuid]);
    $subscription = reset($subscriptions);
    return $subscription instanceof LwsSubscriptionInterface && $subscription->uuid() === $uuid ? $subscription : NULL;
  }

  /**
   * A subscription by ID.
   */
  public function loadById(int $id): ?LwsSubscriptionInterface {
    $subscription = $this->storage()->load($id);
    return $subscription instanceof LwsSubscriptionInterface ? $subscription : NULL;
  }

  /**
   * One page of an agent's live subscriptions at a storage, oldest first.
   *
   * @param \Drupal\lws\Storage\StorageRef $storage
   *   The storage.
   * @param string $agent
   *   The agent.
   * @param int $after
   *   The ID after which the page starts; 0 for the first.
   * @param int $size
   *   The most subscriptions on the page.
   *
   * @return array{list<\Drupal\lws_notify\Entity\LwsSubscriptionInterface>, int, int|null}
   *   The subscriptions, how many the agent holds in all, and the ID after
   *   which the next page starts, if there is one.
   */
  public function page(StorageRef $storage, string $agent, int $after, int $size): array {
    $total = $this->countLive($storage, $agent);
    $ids = array_values((array) $this->liveQuery($storage->id)
      ->condition('agent', $agent)
      ->condition('id', $after, '>')
      ->sort('id')
      ->range(0, $size + 1)
      ->execute());
    $page = array_values(array_filter($this->storage()->loadMultiple(array_slice($ids, 0, $size)), static fn ($s): bool => $s instanceof LwsSubscriptionInterface));
    $next = count($ids) > $size && $page !== [] ? (int) $page[count($page) - 1]->id() : NULL;
    return [$page, $total, $next];
  }

  /**
   * The subscriptions of a storage, newest first, for administrators.
   *
   * @return list<\Drupal\lws_notify\Entity\LwsSubscriptionInterface>
   *   The subscriptions, live or ended.
   */
  public function forStorage(int $storageId): array {
    $ids = $this->storage()->getQuery()->accessCheck(FALSE)->condition('storage', $storageId)->sort('id', 'DESC')->execute();
    return array_values(array_filter($this->storage()->loadMultiple($ids), static fn ($s): bool => $s instanceof LwsSubscriptionInterface));
  }

  /**
   * The live subscriptions of a storage whose topics cover a resource.
   *
   * @return list<\Drupal\lws_notify\Entity\LwsSubscriptionInterface>
   *   The subscriptions.
   */
  public function covering(ResourceContext $resource): array {
    $id = $resource->storage->id;
    if (!isset($this->live[$id])) {
      $ids = (array) $this->liveQuery($id)->execute();
      $this->live[$id] = array_values(array_filter($this->storage()->loadMultiple($ids), static fn ($s): bool => $s instanceof LwsSubscriptionInterface));
    }
    $now = $this->time->getCurrentTime();
    return array_values(array_filter($this->live[$id], static fn (LwsSubscriptionInterface $s): bool => $s->isLive($now) && self::covers($s, $resource)));
  }

  /**
   * Whether a subscription's topics cover a resource (§10.3.2).
   */
  public static function covers(LwsSubscriptionInterface $subscription, ResourceContext $resource): bool {
    foreach ($subscription->getTopics() as $topic) {
      // The resource itself; or a container above it, whose URI ends in a
      // slash, as only a container's does.
      if ($topic === $resource->uri || in_array($topic, $resource->ancestors, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Records what an inbox answered to a delivery.
   *
   * @param \Drupal\lws_notify\Entity\LwsSubscriptionInterface $subscription
   *   The subscription.
   * @param int $status
   *   The HTTP status; 0 when there was none.
   * @param bool $delivered
   *   Whether the delivery succeeded.
   * @param bool $gone
   *   Whether the inbox said it is gone for good (410).
   *
   * @return bool
   *   Whether the subscription is still active.
   */
  public function recordOutcome(LwsSubscriptionInterface $subscription, int $status, bool $delivered, bool $gone): bool {
    $failures = $delivered ? 0 : $subscription->getFailures() + 1;
    $max = (int) $this->configFactory->get('lws_notify.settings')->get('delivery.max_failures');
    $active = $subscription->isActive() && !$gone && $failures < max(1, $max);
    $subscription->set('failures', $failures);
    $subscription->set('last_status', $status);
    $subscription->set('last_attempt', $this->time->getCurrentTime());
    $subscription->set('active', $active);
    $subscription->save();
    $this->reset();
    return $active;
  }

  /**
   * Cancels a subscription.
   */
  public function delete(LwsSubscriptionInterface $subscription): void {
    $subscription->delete();
    $this->reset();
  }

  /**
   * Deletes the subscriptions of a storage, as when it is deleted.
   */
  public function deleteForStorage(int $storageId): void {
    $ids = $this->storage()->getQuery()->accessCheck(FALSE)->condition('storage', $storageId)->execute();
    foreach (array_chunk(array_values($ids), 100) as $chunk) {
      $this->storage()->delete($this->storage()->loadMultiple($chunk));
    }
    $this->reset();
  }

  /**
   * Deletes subscriptions that ended more than a week ago.
   *
   * @return int
   *   How many were deleted.
   */
  public function purge(): int {
    $before = $this->time->getCurrentTime() - self::KEEP_ENDED;
    $query = $this->storage()->getQuery()->accessCheck(FALSE);
    $query->condition($query->orConditionGroup()
      ->condition('expires', $before, '<')
      ->condition($query->andConditionGroup()
        ->condition('active', 0)
        ->condition('last_attempt', $before, '<')));
    $ids = array_values($query->range(0, 500)->execute());
    if ($ids !== []) {
      $this->storage()->delete($this->storage()->loadMultiple($ids));
      $this->reset();
    }
    return count($ids);
  }

  /**
   * The URI of a subscription.
   */
  public function uri(StorageRef $storage, LwsSubscriptionInterface $subscription): string {
    return $this->urls->notificationsUri($storage->slug, (string) $subscription->uuid());
  }

  /**
   * The representation of a subscription.
   *
   * @return array<string, mixed>
   *   Its current state, as lws-server shows it: whether it is still active
   *   (expired or deactivated), with what it was made for.
   */
  public function document(StorageRef $storage, LwsSubscriptionInterface $subscription): array {
    $uri = $this->uri($storage, $subscription);
    $document = [
      '@context' => Vocabulary::LWS_CONTEXT,
      'id' => $uri,
      'type' => $subscription->getType(),
      'subscription' => $uri,
      'topic' => $subscription->getTopics(),
      'inbox' => $subscription->getInbox(),
    ];
    $expires = $subscription->getExpires();
    if ($expires !== NULL) {
      $document['expires'] = gmdate('Y-m-d\TH:i:s\Z', $expires);
    }
    $document['active'] = $subscription->isLive($this->time->getCurrentTime());
    return $document;
  }

  /**
   * Forgets the live subscriptions loaded, as when one changes.
   */
  public function reset(): void {
    $this->live = [];
  }

  /**
   * How many live subscriptions an agent holds at a storage.
   */
  private function countLive(StorageRef $storage, string $agent): int {
    return (int) $this->liveQuery($storage->id)->condition('agent', $agent)->count()->execute();
  }

  /**
   * A query for the live subscriptions of a storage.
   */
  private function liveQuery(int $storageId): QueryInterface {
    $query = $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('storage', $storageId)
      ->condition('active', 1);
    $query->condition($query->orConditionGroup()
      ->notExists('expires')
      ->condition('expires', $this->time->getCurrentTime(), '>'));
    return $query;
  }

  /**
   * The entity storage of subscriptions.
   */
  private function storage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('lws_subscription');
  }

}
