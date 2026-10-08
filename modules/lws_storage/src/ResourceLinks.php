<?php

declare(strict_types=1);

namespace Drupal\lws_storage;

use Drupal\lws\Access\ResourceContext;
use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws_storage\Entity\LwsResourceInterface;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Ebremer\Lws\Http\LinkHeader;
use Ebremer\Lws\LinkRelation;
use Ebremer\Lws\MediaType;
use Ebremer\Lws\ResourceType;

/**
 * URIs, server-managed links and access contexts of resources.
 */
final class ResourceLinks {

  public function __construct(
    private readonly LwsUrlGenerator $urls,
    private readonly StorageRegistry $storages,
  ) {}

  /**
   * The URI of a resource.
   */
  public function uri(LwsStorageInterface $storage, LwsResourceInterface $resource): string {
    return $this->urls->resourceUri($storage->getSlug(), $resource->getSegments(), $resource->isContainer());
  }

  /**
   * The URI of a resource's linkset.
   */
  public function linksetUri(LwsStorageInterface $storage, LwsResourceInterface $resource): string {
    return $this->urls->metaUri($storage->getSlug(), (string) $resource->uuid());
  }

  /**
   * The LWS class of a resource.
   */
  public function type(LwsResourceInterface $resource): string {
    return $resource->isContainer() ? ResourceType::CONTAINER : ResourceType::DATA_RESOURCE;
  }

  /**
   * The server-managed links of a resource (LWS Core §8.1, §9.1).
   *
   * @return list<string>
   *   Link header values: the storage, the LWS class and the types clients
   *   declared, the parent container, and the linkset.
   */
  public function headers(LwsStorageInterface $storage, LwsResourceInterface $resource): array {
    $links = [
      LinkHeader::format($this->urls->storageUri($storage->getSlug()), LinkRelation::STORAGE),
      LinkHeader::format($this->type($resource), LinkRelation::TYPE),
    ];
    foreach ($resource->getUserMetadata()->types as $type) {
      $links[] = LinkHeader::format($type, LinkRelation::TYPE);
    }
    $parent = $resource->getParent();
    if ($parent !== NULL) {
      $links[] = LinkHeader::format($this->uri($storage, $parent), LinkRelation::UP);
    }
    $links[] = LinkHeader::format($this->linksetUri($storage, $resource), LinkRelation::LINKSET, ['type' => MediaType::LINKSET_JSON]);
    return $links;
  }

  /**
   * What the policy decision point needs about a resource, existing or not.
   *
   * @param \Drupal\lws_storage\Entity\LwsStorageInterface $storage
   *   The storage.
   * @param list<string> $segments
   *   The decoded names from the root container down.
   * @param bool $container
   *   Whether it is a container.
   * @param \Drupal\lws_storage\Entity\LwsResourceInterface|null $resource
   *   The resource, if it exists.
   */
  public function context(LwsStorageInterface $storage, array $segments, bool $container, ?LwsResourceInterface $resource = NULL): ResourceContext {
    $slug = $storage->getSlug();
    $ancestors = [];
    for ($i = 1; $i < count($segments); $i++) {
      $ancestors[] = $this->urls->resourceUri($slug, array_slice($segments, 0, $i), TRUE);
    }
    return new ResourceContext(
      $this->storages->ref($storage),
      $this->urls->resourceUri($slug, $segments, $container),
      $ancestors,
      $container,
      $resource?->getMediaType(),
      $resource === NULL ? [] : $this->types($resource),
    );
  }

  /**
   * All the types of a resource: its LWS class, and those its clients set.
   *
   * @return list<string>
   *   The type URIs, as its Link headers give them.
   */
  public function types(LwsResourceInterface $resource): array {
    return array_values(array_unique([$this->type($resource), ...$resource->getUserMetadata()->types]));
  }

  /**
   * The access context of an existing resource.
   */
  public function contextOf(LwsStorageInterface $storage, LwsResourceInterface $resource): ResourceContext {
    return $this->context($storage, $resource->getSegments(), $resource->isContainer(), $resource);
  }

}
