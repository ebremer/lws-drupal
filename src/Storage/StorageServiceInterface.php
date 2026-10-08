<?php

declare(strict_types=1);

namespace Drupal\lws\Storage;

/**
 * A service that a storage advertises in its storage description.
 *
 * Implementations are tagged "lws.storage_service". Each contributes entries
 * to the description's "service" set (LWS Core §6.1.4), for example the
 * StorageRoot, or an AccessGrantService when lws_authz is enabled.
 */
interface StorageServiceInterface {

  /**
   * The service entries for one storage.
   *
   * @param string $storageUri
   *   The storage URI, ending in a slash.
   * @param string $slug
   *   The storage slug.
   *
   * @return list<array<string, mixed>>
   *   Service objects, each with at least "type" and "serviceEndpoint".
   */
  public function services(string $storageUri, string $slug): array;

}
