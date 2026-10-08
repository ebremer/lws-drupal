<?php

declare(strict_types=1);

namespace Drupal\lws_notify;

/**
 * A subscription request, as the parser read it.
 */
final class SubscriptionRequest {

  /**
   * Constructs a subscription request.
   *
   * @param string $type
   *   The subscription type.
   * @param array<string, \Drupal\lws\Access\ResourceContext> $topics
   *   The topics: canonical resource URIs, each with what the policy decision
   *   point needs to decide whether the subscriber may read it.
   * @param string $inbox
   *   The inbox URL.
   * @param int|null $expires
   *   When it expires, in seconds since the epoch, within the site's limit;
   *   NULL for never.
   */
  public function __construct(
    public readonly string $type,
    public readonly array $topics,
    public readonly string $inbox,
    public readonly ?int $expires,
  ) {}

}
