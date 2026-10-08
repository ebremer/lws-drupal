<?php

declare(strict_types=1);

namespace Drupal\lws_notify\Entity;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * A subscription to changes in a storage (LWS Core §10.3).
 */
interface LwsSubscriptionInterface extends ContentEntityInterface {

  /**
   * The ID of the storage it belongs to.
   */
  public function getStorageId(): int;

  /**
   * The subscription type, such as "WebhookSubscription".
   */
  public function getType(): string;

  /**
   * The agent who subscribed.
   */
  public function getAgent(): string;

  /**
   * The client the agent subscribed with, if its token named one.
   */
  public function getClient(): ?string;

  /**
   * The URIs of the resources it is about.
   *
   * @return list<string>
   *   Resource URIs; a container's covers everything in it.
   */
  public function getTopics(): array;

  /**
   * The URL notifications are delivered to.
   */
  public function getInbox(): string;

  /**
   * When it expires, in seconds since the epoch; NULL for never.
   */
  public function getExpires(): ?int;

  /**
   * Whether it is still delivered to: active and not expired.
   */
  public function isLive(int $now): bool;

  /**
   * Whether it is active; a failing inbox deactivates it.
   */
  public function isActive(): bool;

  /**
   * The deliveries in a row that failed.
   */
  public function getFailures(): int;

}
