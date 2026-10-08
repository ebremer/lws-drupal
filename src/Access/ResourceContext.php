<?php

declare(strict_types=1);

namespace Drupal\lws\Access;

use Drupal\lws\Storage\StorageRef;

/**
 * What the policy decision point knows about the resource of a request.
 *
 * Built from the request URL, with the format and types of the resource if it
 * exists. A resource that does not exist has neither, so a policy limited to
 * some formats or types denies it, as it denies an existing resource of
 * another format or type: denial does not reveal existence.
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
   *   For an existing resource, its types: its LWS class, and those declared
   *   for it with rel="type" links.
   * @param \Drupal\lws\Access\ResourceChange|null $change
   *   What the request would make of it: for Create, the new resource in
   *   this container; for Modify, its new format or types, if it changes
   *   them.
   */
  public function __construct(
    public readonly StorageRef $storage,
    public readonly string $uri,
    public readonly array $ancestors,
    public readonly bool $container,
    public readonly ?string $mediaType = NULL,
    public readonly array $types = [],
    public readonly ?ResourceChange $change = NULL,
  ) {}

  /**
   * This context, for a request that would make a change.
   */
  public function withChange(ResourceChange $change): self {
    return new self($this->storage, $this->uri, $this->ancestors, $this->container, $this->mediaType, $this->types, $change);
  }

}
