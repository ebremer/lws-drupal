<?php

declare(strict_types=1);

namespace Drupal\lws_index\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\lws_index\Indexer;
use Drupal\lws_storage\Entity\LwsResourceInterface;

/**
 * Keeps the index as resources are created, changed and deleted.
 *
 * Entity hooks run inside the transaction that saves or deletes the
 * resource, so the index changes with it, or not at all.
 */
final class LwsIndexHooks {

  public function __construct(
    private readonly Indexer $indexer,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_insert() for lws_resource.
   */
  #[Hook('lws_resource_insert')]
  public function resourceInsert(EntityInterface $resource): void {
    if ($resource instanceof LwsResourceInterface) {
      $this->indexer->index($resource);
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_update() for lws_resource.
   */
  #[Hook('lws_resource_update')]
  public function resourceUpdate(EntityInterface $resource): void {
    if ($resource instanceof LwsResourceInterface) {
      $this->indexer->index($resource);
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for lws_resource.
   */
  #[Hook('lws_resource_delete')]
  public function resourceDelete(EntityInterface $resource): void {
    $this->indexer->remove((int) $resource->id());
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for lws_storage.
   *
   * Its resources were deleted with it; anything left of them goes too.
   */
  #[Hook('lws_storage_delete')]
  public function storageDelete(EntityInterface $storage): void {
    $this->indexer->removeStorage((int) $storage->id());
  }

}
