<?php

declare(strict_types=1);

namespace Drupal\lws_storage;

use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws\Storage\StorageServiceInterface;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\Http\JsonPatches;
use Ebremer\Lws\MediaType;
use Ebremer\Lws\ResourceType;
use Ebremer\Lws\Vocabulary;

/**
 * Builds storage descriptions (LWS Core §6.1).
 *
 * The service set comes from services tagged "lws.storage_service", in order
 * of priority.
 */
final class StorageDescriptionBuilder {

  /**
   * The services the storage advertises.
   *
   * @var list<\Drupal\lws\Storage\StorageServiceInterface>
   */
  private array $services = [];

  public function __construct(
    private readonly LwsUrlGenerator $urls,
  ) {}

  /**
   * Adds a service; called by the service collector.
   */
  public function addService(StorageServiceInterface $service): void {
    $this->services[] = $service;
  }

  /**
   * The storage description, as a controlled identifier document.
   *
   * @return array<string, mixed>
   *   The document.
   */
  public function build(LwsStorageInterface $storage): array {
    $uri = $this->urls->storageUri($storage->getSlug());
    $services = [];
    foreach ($this->services as $service) {
      array_push($services, ...$service->services($uri, $storage->getSlug()));
    }
    return [
      '@context' => [Vocabulary::CID_CONTEXT, Vocabulary::LWS_CONTEXT],
      'id' => $uri,
      'type' => ResourceType::STORAGE,
      // The patch formats JSON resources and linksets take. The IRI is the
      // one lws-server uses; the vocabulary has none yet (DESIGN.md D3), and
      // Accept-Patch is what clients rely on.
      'capability' => [
        [
          'type' => Vocabulary::LWS_NS . 'PatchSupport',
          'format' => [
            MediaType::JSON => JsonPatches::ACCEPTED,
            MediaType::LINKSET_JSON => JsonPatches::ACCEPTED,
          ],
        ],
      ],
      'service' => $services,
    ];
  }

}
