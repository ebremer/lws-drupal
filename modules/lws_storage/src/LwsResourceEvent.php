<?php

declare(strict_types=1);

namespace Drupal\lws_storage;

use Drupal\Component\EventDispatcher\Event;
use Drupal\lws\Access\ResourceContext;

/**
 * A container or data resource was created, changed or deleted.
 *
 * Dispatched once the change is committed (DESIGN.md §5.4), so a listener
 * never hears of a change that was rolled back. A recursive delete
 * dispatches one event per resource it removed, its members first.
 * lws_notify turns these into notifications; nothing in the storage depends
 * on them.
 */
final class LwsResourceEvent extends Event {

  /**
   * A resource was created in a container.
   */
  public const CREATED = 'lws_storage.resource.created';

  /**
   * A data resource's content was replaced or patched.
   */
  public const UPDATED = 'lws_storage.resource.updated';

  /**
   * The links clients manage of a resource were changed.
   */
  public const METADATA_UPDATED = 'lws_storage.resource.metadata_updated';

  /**
   * A resource was deleted.
   */
  public const DELETED = 'lws_storage.resource.deleted';

  /**
   * Constructs the event.
   *
   * @param \Drupal\lws\Access\ResourceContext $resource
   *   What the policy decision point knows about the resource: as it is now,
   *   or for a deleted one, as it was.
   * @param int $id
   *   The ID of its lws_resource entity.
   * @param string $uuid
   *   Its UUID.
   * @param float $time
   *   When the change was made, in seconds since the epoch.
   */
  public function __construct(
    public readonly ResourceContext $resource,
    public readonly int $id,
    public readonly string $uuid,
    public readonly float $time,
  ) {}

  /**
   * The URI of the container that holds the resource; NULL for the root.
   */
  public function parentUri(): ?string {
    $ancestors = $this->resource->ancestors;
    return $ancestors === [] ? NULL : $ancestors[count($ancestors) - 1];
  }

}
