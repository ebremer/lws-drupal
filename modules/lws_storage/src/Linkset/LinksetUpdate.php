<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Linkset;

/**
 * What a write of a linkset asks for (LWS Core §9.1, Moving Resources).
 *
 * The links clients manage, and the container the resource is to be in: a
 * client moves a resource by changing the "up" link of its linkset.
 */
final class LinksetUpdate {

  /**
   * Constructs a linkset update.
   *
   * @param \Drupal\lws_storage\Linkset\UserMetadata $metadata
   *   The links clients manage, which replace the current ones.
   * @param string|null $up
   *   The container the "up" link names, as an absolute URI: resolved
   *   against the linkset's URL, but otherwise as the client wrote it. NULL
   *   when the write leaves the link out, as a PUT may, which keeps the
   *   resource where it is.
   */
  public function __construct(
    public readonly UserMetadata $metadata,
    public readonly ?string $up = NULL,
  ) {}

}
