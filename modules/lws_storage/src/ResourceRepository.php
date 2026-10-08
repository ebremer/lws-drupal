<?php

declare(strict_types=1);

namespace Drupal\lws_storage;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lws\Routing\LwsArea;
use Drupal\lws\Routing\LwsTarget;
use Drupal\lws_storage\Entity\LwsResourceInterface;
use Drupal\lws_storage\Entity\LwsStorageInterface;

/**
 * Finds resources by path, and lists and touches containers.
 */
final class ResourceRepository {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * The storage root container.
   *
   * @throws \LogicException
   *   When the storage has none, which StorageManager never allows.
   */
  public function root(LwsStorageInterface $storage): LwsResourceInterface {
    return $this->findByPath($storage, 'root/') ?? throw new \LogicException(sprintf('Storage %s has no root container.', $storage->getSlug()));
  }

  /**
   * The resource at a decoded path, such as "root/notes/", if it exists.
   */
  public function findByPath(LwsStorageInterface $storage, string $path): ?LwsResourceInterface {
    $ids = $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('storage', $storage->id())
      ->condition('path_hash', hash('sha256', $path))
      ->execute();
    foreach ($this->storage()->loadMultiple($ids) as $resource) {
      if ($resource instanceof LwsResourceInterface && $resource->getPath() === $path) {
        return $resource;
      }
    }
    return NULL;
  }

  /**
   * The resource a request URL addresses, if it exists.
   */
  public function findByTarget(LwsStorageInterface $storage, LwsTarget $target): ?LwsResourceInterface {
    if ($target->area !== LwsArea::Resource) {
      return NULL;
    }
    return $this->findByPath($storage, implode('/', $target->segments) . ($target->container ? '/' : ''));
  }

  /**
   * The members of a container, ordered by name.
   *
   * @return list<\Drupal\lws_storage\Entity\LwsResourceInterface>
   *   The contained resources.
   */
  public function children(LwsResourceInterface $container): array {
    $ids = $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('parent', $container->id())
      ->sort('name')
      ->execute();
    return array_values(array_filter(
      $this->storage()->loadMultiple($ids),
      static fn ($resource): bool => $resource instanceof LwsResourceInterface,
    ));
  }

  /**
   * Members of a container in name order, after a name.
   *
   * @param \Drupal\lws_storage\Entity\LwsResourceInterface $container
   *   The container.
   * @param string|null $after
   *   The name the members follow; NULL to start with the first.
   * @param int $limit
   *   The most members to return.
   *
   * @return list<\Drupal\lws_storage\Entity\LwsResourceInterface>
   *   The members.
   */
  public function membersAfter(LwsResourceInterface $container, ?string $after, int $limit): array {
    $query = $this->database->select('lws_resource', 'r')
      ->fields('r', ['id'])
      ->condition('parent', $container->id())
      ->orderBy('name')
      ->range(0, $limit);
    if ($after !== NULL) {
      $query->condition('name', $after, '>');
    }
    $ids = $query->execute()?->fetchCol() ?? [];
    $loaded = $this->storage()->loadMultiple($ids);
    $members = [];
    foreach ($ids as $id) {
      if (($loaded[$id] ?? NULL) instanceof LwsResourceInterface) {
        $members[] = $loaded[$id];
      }
    }
    return $members;
  }

  /**
   * The names of a container's members before a name, nearest first.
   *
   * @return list<string>
   *   At most $limit names.
   */
  public function namesBefore(LwsResourceInterface $container, string $before, int $limit): array {
    return array_values(array_map('strval', $this->database->select('lws_resource', 'r')
      ->fields('r', ['name'])
      ->condition('parent', $container->id())
      ->condition('name', $before, '<')
      ->orderBy('name', 'DESC')
      ->range(0, $limit)
      ->execute()?->fetchCol() ?? []));
  }

  /**
   * The name of the member at a position in name order, counting from 0.
   */
  public function nameAt(LwsResourceInterface $container, int $position): ?string {
    $name = $this->database->select('lws_resource', 'r')
      ->fields('r', ['name'])
      ->condition('parent', $container->id())
      ->orderBy('name')
      ->range($position, 1)
      ->execute()?->fetchField();
    return is_string($name) ? $name : NULL;
  }

  /**
   * The number of members of a container.
   */
  public function countMembers(LwsResourceInterface $container): int {
    return (int) $this->database->select('lws_resource', 'r')
      ->condition('parent', $container->id())
      ->countQuery()
      ->execute()?->fetchField();
  }

  /**
   * The member of a container with a stored name, if there is one.
   *
   * @param \Drupal\lws_storage\Entity\LwsResourceInterface $container
   *   The container.
   * @param string $name
   *   The decoded name, with a slash for a container.
   */
  public function findChild(LwsResourceInterface $container, string $name): ?LwsResourceInterface {
    $ids = $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('parent', $container->id())
      ->condition('name', $name)
      ->execute();
    foreach ($this->storage()->loadMultiple($ids) as $resource) {
      // The database may compare case-insensitively.
      if ($resource instanceof LwsResourceInterface && $resource->getName() === $name) {
        return $resource;
      }
    }
    return NULL;
  }

  /**
   * The resource with a UUID in a storage, if there is one.
   */
  public function findByUuid(LwsStorageInterface $storage, string $uuid): ?LwsResourceInterface {
    $resources = $this->storage()->loadByProperties(['storage' => $storage->id(), 'uuid' => $uuid]);
    $resource = reset($resources);
    return $resource instanceof LwsResourceInterface ? $resource : NULL;
  }

  /**
   * All resources below a container, at any depth.
   *
   * @param \Drupal\lws_storage\Entity\LwsResourceInterface $container
   *   The container.
   * @param bool $lock
   *   Whether to lock their rows, inside a transaction, until it ends.
   *
   * @return list<\Drupal\lws_storage\Entity\LwsResourceInterface>
   *   The resources, deepest first.
   */
  public function descendants(LwsResourceInterface $container, bool $lock = FALSE): array {
    $paths = $this->descendantPaths($container, $lock);
    uasort($paths, static fn (string $a, string $b): int => substr_count($b, '/') <=> substr_count($a, '/') ?: strcmp($b, $a));
    $resources = $this->storage()->loadMultiple(array_keys($paths));
    return array_values(array_filter($resources, static fn ($resource): bool => $resource instanceof LwsResourceInterface));
  }

  /**
   * Whether a container has any member.
   */
  public function hasMembers(LwsResourceInterface $container): bool {
    return $this->database->select('lws_resource', 'r')
      ->fields('r', ['id'])
      ->condition('parent', $container->id())
      ->range(0, 1)
      ->execute()
      ?->fetchField() !== FALSE;
  }

  /**
   * How many resources are below a container, counting no further than a cap.
   *
   * Nothing is loaded or locked, so a container too large to delete costs
   * little to refuse.
   *
   * @return int
   *   The number of descendants, or the cap if there are more.
   */
  public function countDescendants(LwsResourceInterface $container, int $cap): int {
    return min($cap, count($this->descendantPaths($container, FALSE, $cap)));
  }

  /**
   * The paths of the resources below a container, by ID.
   *
   * @param \Drupal\lws_storage\Entity\LwsResourceInterface $container
   *   The container.
   * @param bool $lock
   *   Whether to lock their rows, inside a transaction, until it ends.
   * @param int|null $cap
   *   Stop after this many rows; NULL for all.
   *
   * @return array<int, string>
   *   The paths.
   */
  private function descendantPaths(LwsResourceInterface $container, bool $lock, ?int $cap = NULL): array {
    $prefix = $container->getPath();
    $query = $this->database->select('lws_resource', 'r')
      ->fields('r', ['id', 'path'])
      ->condition('storage', $container->getLwsStorageId())
      ->condition('path', $this->database->escapeLike($prefix) . '_%', 'LIKE');
    if ($cap !== NULL) {
      $query->range(0, $cap);
    }
    if ($lock) {
      $query->forUpdate();
    }
    $paths = [];
    foreach ($query->execute() ?? [] as $row) {
      // LIKE may ignore case; paths are case-sensitive. Rows that differ in
      // case may make a capped count too low, never too high.
      if (str_starts_with((string) $row->path, $prefix)) {
        $paths[(int) $row->id] = (string) $row->path;
      }
    }
    return $paths;
  }

  /**
   * Records a change to a container's membership or its members' metadata.
   *
   * Increments the version in the database rather than through the loaded
   * entity, so that concurrent changes to one container are all counted. Call
   * it inside the transaction that makes the change; it locks the row until
   * the transaction ends.
   *
   * @return bool
   *   FALSE if the container no longer exists.
   */
  public function touch(LwsResourceInterface $container): bool {
    $updated = $this->database->update('lws_resource')
      ->expression('version', '[version] + 1')
      ->fields(['changed' => $this->time->getRequestTime()])
      ->condition('id', $container->id())
      ->execute();
    $this->storage()->resetCache([(int) $container->id()]);
    return $updated > 0;
  }

  /**
   * The entity storage of resources.
   */
  private function storage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('lws_resource');
  }

}
