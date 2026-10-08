<?php

declare(strict_types=1);

namespace Drupal\lws_notify\Delivery;

use Drupal\lws\Access\ResourceContext;
use Ebremer\Lws\ResourceType;
use Ebremer\Lws\Vocabulary;

/**
 * One notification to deliver to one inbox: its activities, batched.
 *
 * A delivery for a subscription keeps the access context of each activity's
 * resource, so that a retry can check again that the subscriber may still
 * read it (LWS Core §10.3.3). A delivery to the inbox of an access request
 * or grant belongs to no subscription.
 */
final class Delivery {

  /**
   * The most activities in one notification.
   */
  public const MAX_ACTIVITIES = 100;

  /**
   * The activities, in the order they happened.
   *
   * @var list<array<string, mixed>>
   */
  private array $activities = [];

  /**
   * The access context of each activity's resource, for subscriptions.
   *
   * @var list<\Drupal\lws\Access\ResourceContext|null>
   */
  private array $contexts = [];

  /**
   * Constructs a delivery.
   *
   * @param string $inbox
   *   The inbox URL.
   * @param string $storage
   *   The URI of the storage the notification is about.
   * @param int|null $subscription
   *   The ID of the subscription it is for; NULL for none.
   * @param int $attempt
   *   Which attempt the next one is, from 1.
   */
  public function __construct(
    public readonly string $inbox,
    public readonly string $storage,
    public readonly ?int $subscription = NULL,
    public int $attempt = 1,
  ) {}

  /**
   * Adds an activity.
   *
   * @param array<string, mixed> $activity
   *   The activity.
   * @param \Drupal\lws\Access\ResourceContext|null $context
   *   Its resource's access context, for a subscription's delivery.
   */
  public function add(array $activity, ?ResourceContext $context = NULL): void {
    $this->activities[] = $activity;
    $this->contexts[] = $context;
  }

  /**
   * Whether it holds as many activities as one notification may.
   */
  public function isFull(): bool {
    return count($this->activities) >= self::MAX_ACTIVITIES;
  }

  /**
   * Whether it has no activity left to deliver.
   */
  public function isEmpty(): bool {
    return $this->activities === [];
  }

  /**
   * Keeps the activities a test accepts.
   *
   * @param callable(array<string, mixed>, \Drupal\lws\Access\ResourceContext|null): bool $keep
   *   Whether to keep an activity.
   */
  public function filter(callable $keep): void {
    $activities = [];
    $contexts = [];
    foreach ($this->activities as $i => $activity) {
      if ($keep($activity, $this->contexts[$i])) {
        $activities[] = $activity;
        $contexts[] = $this->contexts[$i];
      }
    }
    $this->activities = $activities;
    $this->contexts = $contexts;
  }

  /**
   * The notification (LWS Core §10.2), as JSON.
   *
   * One activity is sent as an object; several, as an array (§10.2.4).
   */
  public function body(): string {
    $notification = [
      '@context' => [Vocabulary::LWS_CONTEXT, Vocabulary::ACTIVITYSTREAMS_CONTEXT],
      'type' => ResourceType::NOTIFICATION,
      'storage' => $this->storage,
      'activity' => count($this->activities) === 1 ? $this->activities[0] : $this->activities,
    ];
    return json_encode($notification, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
  }

  /**
   * The delivery as a queue item.
   *
   * @param int $notBefore
   *   When it may be tried, in seconds since the epoch.
   *
   * @return array<string, mixed>
   *   The item.
   */
  public function toItem(int $notBefore): array {
    return [
      'inbox' => $this->inbox,
      'storage' => $this->storage,
      'subscription' => $this->subscription,
      'attempt' => $this->attempt,
      'not_before' => $notBefore,
      'activities' => $this->activities,
      'contexts' => $this->contexts,
    ];
  }

  /**
   * A delivery from a queue item.
   *
   * @param mixed $item
   *   The item.
   *
   * @return self|null
   *   The delivery, or NULL if the item is not one.
   */
  public static function fromItem(mixed $item): ?self {
    if (!is_array($item) || !is_string($item['inbox'] ?? NULL) || !is_string($item['storage'] ?? NULL) || !is_array($item['activities'] ?? NULL) || !is_array($item['contexts'] ?? NULL)) {
      return NULL;
    }
    $delivery = new self($item['inbox'], $item['storage'], is_int($item['subscription'] ?? NULL) ? $item['subscription'] : NULL, max(1, (int) ($item['attempt'] ?? 1)));
    foreach (array_values($item['activities']) as $i => $activity) {
      $context = $item['contexts'][$i] ?? NULL;
      if (is_array($activity)) {
        $delivery->add($activity, $context instanceof ResourceContext ? $context : NULL);
      }
    }
    return $delivery;
  }

}
