<?php

declare(strict_types=1);

namespace Drupal\lws\Storage;

/**
 * Keys that a storage publishes in its storage description.
 *
 * A service tagged "lws.storage_service" may also implement this interface.
 * Its verification methods go in the description's "verificationMethod",
 * and each is referenced from "authentication" (Controlled Identifiers 1.0
 * §2.3). lws_notify publishes the key it signs webhook deliveries with this
 * way (lws10-notifications-webhook).
 */
interface StorageKeysInterface {

  /**
   * The verification methods for one storage.
   *
   * @param string $storageUri
   *   The storage URI, ending in a slash.
   * @param string $slug
   *   The storage slug.
   *
   * @return list<array<string, mixed>>
   *   Verification methods, each with an "id" that is the storage URI with a
   *   fragment, a "type", the storage as its "controller", and a public key.
   */
  public function verificationMethods(string $storageUri, string $slug): array;

}
