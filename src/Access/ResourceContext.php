<?php

declare(strict_types=1);

namespace Drupal\lws\Access;

use Drupal\lws\Storage\StorageRef;

/**
 * What the policy decision point knows about the resource of a request.
 *
 * Built from the request URL, so that a decision about a resource that does
 * not exist is made the same way as about one that does: denial must not
 * reveal existence.
 */
final class ResourceContext {

  /**
   * Constructs a resource context.
   *
   * @param \Drupal\lws\Storage\StorageRef $storage
   *   The storage that contains the resource.
   * @param string $uri
   *   The resource URI.
   * @param list<string> $ancestors
   *   The URIs of the containers above it, from the root container down.
   * @param bool $container
   *   Whether it is a container.
   * @param string|null $mediaType
   *   For an existing data resource, its media type.
   * @param list<string> $types
   *   The types declared for it with rel="type" links.
   */
  public function __construct(
    public readonly StorageRef $storage,
    public readonly string $uri,
    public readonly array $ancestors,
    public readonly bool $container,
    public readonly ?string $mediaType = NULL,
    public readonly array $types = [],
  ) {}

}
