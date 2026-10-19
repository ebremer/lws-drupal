<?php

declare(strict_types=1);

namespace Drupal\lws_storage;

use Drupal\lws\Access\AccessDecisionInterface;
use Drupal\lws\Access\Action;
use Drupal\lws\Access\ResourceChange;
use Drupal\lws\Agent\RequestingAgent;
use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws\Storage\StorageKeysInterface;
use Drupal\lws\Storage\StorageServiceInterface;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\Http\JsonPatches;
use Ebremer\Lws\CapabilityType;
use Ebremer\Lws\MediaType;
use Ebremer\Lws\ResourceType;
use Ebremer\Lws\Vocabulary;

/**
 * Builds storage descriptions (LWS Core §6.1).
 *
 * The service set comes from services tagged "lws.storage_service", in order
 * of priority, and so do the keys of those that publish keys. The
 * capabilities are patch support, and moving resources between containers
 * where clients may (ResourceMoves).
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
    private readonly ResourceMoves $moves,
    private readonly ResourceLinks $links,
    private readonly AccessDecisionInterface $decisions,
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
    $methods = [];
    foreach ($this->services as $service) {
      array_push($services, ...$service->services($uri, $storage->getSlug()));
      if ($service instanceof StorageKeysInterface) {
        array_push($methods, ...$service->verificationMethods($uri, $storage->getSlug()));
      }
    }
    $keys = $methods === [] ? [] : [
      'verificationMethod' => $methods,
      'authentication' => array_map(static fn (array $method): mixed => $method['id'], $methods),
    ];
    return [
      '@context' => [Vocabulary::CID_CONTEXT, Vocabulary::LWS_CONTEXT],
      'id' => $uri,
      'type' => ResourceType::STORAGE,
    ] + $keys + [
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
        ...$this->moveCapability($storage),
      ],
      'service' => $services,
    ];
  }

  /**
   * The MoveResource capability, where clients may move resources.
   *
   * Every URI here is derived from the containment hierarchy, so a moved
   * resource's URI changes: "preservesIdentifier" is false. A storage that
   * takes the writes of a move from nobody allows no move, and so describes
   * none (LWS Core, Moving Resources).
   *
   * @return list<array<string, mixed>>
   *   The capability, or none.
   */
  private function moveCapability(LwsStorageInterface $storage): array {
    $kinds = $this->moves->appliesTo();
    if ($kinds === [] || !$this->takesWrites($storage)) {
      return [];
    }
    return [
      [
        'type' => CapabilityType::MOVE_RESOURCE,
        'appliesTo' => $kinds,
        'preservesIdentifier' => FALSE,
      ],
    ];
  }

  /**
   * Whether a storage takes the writes a move makes, from anyone at all.
   *
   * Not where the policy decision point refuses them even to an agent that
   * controls every storage, as it does for a projection, which refuses every
   * write (lws_projection).
   */
  private function takesWrites(LwsStorageInterface $storage): bool {
    $root = $this->links->context($storage, ['root'], TRUE);
    $controller = new RequestingAgent($root->storage->uri, controlsEveryStorage: TRUE);
    foreach ([Action::Modify, Action::Delete, Action::Create] as $action) {
      $context = $action === Action::Modify ? $root->withChange(new ResourceChange(typesUnknown: TRUE)) : $root;
      if (!$this->decisions->decide($controller, $action, $context)->isPermitted()) {
        return FALSE;
      }
    }
    return TRUE;
  }

}
