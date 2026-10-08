<?php

declare(strict_types=1);

namespace Drupal\lws\Storage;

/**
 * Finds storages by slug, for modules that do not depend on lws_storage.
 *
 * Implemented by lws_storage as the service with this interface's name.
 */
interface StorageRegistryInterface {

  /**
   * The storage with a slug, or NULL if there is none.
   */
  public function get(string $slug): ?StorageRef;

}
