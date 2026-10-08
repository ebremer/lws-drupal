<?php

declare(strict_types=1);

namespace Drupal\lws_storage;

use Drupal\lws\Storage\StorageServiceInterface;
use Ebremer\Lws\ServiceType;

/**
 * The StorageRoot service every storage description must contain (§6.1.4).
 */
final class StorageRootService implements StorageServiceInterface {

  /**
   * {@inheritdoc}
   */
  public function services(string $storageUri, string $slug): array {
    return [['type' => ServiceType::STORAGE_ROOT, 'serviceEndpoint' => $storageUri . 'root/']];
  }

}
