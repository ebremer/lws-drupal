<?php

declare(strict_types=1);

namespace Drupal\lws_storage;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws\Storage\StorageRef;
use Drupal\lws\Storage\StorageRegistryInterface;
use Drupal\lws_storage\Entity\LwsStorageInterface;

/**
 * Finds storages for modules that do not depend on this one.
 */
final class StorageRegistry implements StorageRegistryInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LwsUrlGenerator $urls,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function get(string $slug): ?StorageRef {
    $storages = $this->entityTypeManager->getStorage('lws_storage')->loadByProperties(['slug' => $slug]);
    foreach ($storages as $storage) {
      // The database may compare case-insensitively.
      if ($storage instanceof LwsStorageInterface && $storage->getSlug() === $slug) {
        return $this->ref($storage);
      }
    }
    return NULL;
  }

  /**
   * The reference to a storage entity.
   */
  public function ref(LwsStorageInterface $storage): StorageRef {
    return new StorageRef(
      (int) $storage->id(),
      $storage->getSlug(),
      $this->urls->storageUri($storage->getSlug()),
      $storage->getControllers(),
      $storage->getAuthorizationServerId(),
      $storage->isEnabled(),
    );
  }

}
