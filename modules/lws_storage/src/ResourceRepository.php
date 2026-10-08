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
   * Records a change to a container's membership.
   *
   * Increments the version in the database rather than through the loaded
   * entity, so that concurrent changes to one container are all counted. Call
   * it inside the transaction that changes the membership.
   */
  public function touch(LwsResourceInterface $container): void {
    $this->database->update('lws_resource')
      ->expression('version', '[version] + 1')
      ->fields(['changed' => $this->time->getRequestTime()])
      ->condition('id', $container->id())
      ->execute();
    $this->storage()->resetCache([(int) $container->id()]);
  }

  /**
   * The entity storage of resources.
   */
  private function storage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('lws_resource');
  }

}
