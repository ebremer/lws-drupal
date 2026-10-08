<?php

declare(strict_types=1);

namespace Drupal\lws_storage;

use Drupal\Core\Database\Connection;
use Drupal\lws\Access\ResourceContext;
use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws_storage\Entity\LwsResourceInterface;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\Linkset\Linksets;
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
    private readonly Connection $database,
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
   * How a container listing describes a resource (LWS Core §8.1).
   *
   * Type searches describe the resources they find the same way
   * (lws10-index).
   *
   * @return array<string, mixed>
   *   Its type, as "Container" or "DataResource" and the types clients
   *   declared, its URI, and for a data resource its format and size; and
   *   when it last changed.
   */
  public function describe(LwsStorageInterface $storage, LwsResourceInterface $resource): array {
    $class = $resource->isContainer() ? 'Container' : 'DataResource';
    $types = $resource->getUserMetadata()->types;
    $description = [
      'type' => $types === [] ? $class : [$class, ...$types],
      'id' => $this->uri($storage, $resource),
    ];
    if (!$resource->isContainer()) {
      $description['format'] = $resource->getMediaType();
      $description['size'] = $resource->getSize();
    }
    $description['modified'] = gmdate('Y-m-d\TH:i:s\Z', $resource->getChangedTime());
    return $description;
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
      // Checked when they were set; checked again, as a header is no place
      // for anything else.
      if (Linksets::isUri($type)) {
        $links[] = LinkHeader::format($type, LinkRelation::TYPE);
      }
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

  /**
   * The access contexts of many resources of one storage, as contextOf().
   *
   * For filtered listings, which judge members by the hundred: the storage's
   * reference and URI, and the URIs of shared ancestors, are made once, and
   * the media types are read in one query rather than through each file
   * entity.
   *
   * @param \Drupal\lws_storage\Entity\LwsStorageInterface $storage
   *   The storage.
   * @param list<\Drupal\lws_storage\Entity\LwsResourceInterface> $resources
   *   Resources of the storage.
   *
   * @return list<\Drupal\lws\Access\ResourceContext>
   *   Their contexts, in the same order.
   */
  public function contextsOf(LwsStorageInterface $storage, array $resources): array {
    $ref = $this->storages->ref($storage);
    $fids = [];
    foreach ($resources as $resource) {
      $fid = (int) $resource->get('content')->target_id;
      if ($fid > 0) {
        $fids[] = $fid;
      }
    }
    $mediaTypes = $fids === [] ? [] : $this->database->select('file_managed', 'f')
      ->fields('f', ['fid', 'filemime'])
      ->condition('fid', $fids, 'IN')
      ->execute()
      ?->fetchAllKeyed() ?? [];
    $ancestors = [];
    $contexts = [];
    foreach ($resources as $resource) {
      $segments = $resource->getSegments();
      $parent = array_slice($segments, 0, -1);
      $key = implode('/', $parent);
      if (!isset($ancestors[$key])) {
        $ancestors[$key] = [];
        for ($i = 1; $i <= count($parent); $i++) {
          $ancestors[$key][] = $ref->uri . self::path(array_slice($parent, 0, $i)) . '/';
        }
      }
      $container = $resource->isContainer();
      $contexts[] = new ResourceContext(
        $ref,
        $ref->uri . self::path($segments) . ($container ? '/' : ''),
        $ancestors[$key],
        $container,
        $mediaTypes[(int) $resource->get('content')->target_id] ?? NULL,
        $this->types($resource),
      );
    }
    return $contexts;
  }

  /**
   * The encoded path of segments below a storage URI.
   *
   * @param list<string> $segments
   *   Decoded names.
   */
  private static function path(array $segments): string {
    return implode('/', array_map(LwsUrlGenerator::encodeSegment(...), $segments));
  }

}
