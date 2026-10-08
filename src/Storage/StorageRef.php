<?php

declare(strict_types=1);

namespace Drupal\lws\Storage;

/**
 * What authorization needs to know about a storage.
 *
 * A plain value, so that lws_authz never loads storage entities.
 */
final class StorageRef {

  /**
   * Constructs a storage reference.
   *
   * @param int $id
   *   The storage entity ID.
   * @param string $slug
   *   The URI segment naming the storage.
   * @param string $uri
   *   The storage URI, which is also the realm of its 401 challenges.
   * @param list<string> $controllers
   *   The agents with full control of the storage.
   * @param string|null $authorizationServer
   *   The authorization server whose tokens it accepts, by ID; NULL for the
   *   site's default.
   */
  public function __construct(
    public readonly int $id,
    public readonly string $slug,
    public readonly string $uri,
    public readonly array $controllers,
    public readonly ?string $authorizationServer = NULL,
  ) {}

}
