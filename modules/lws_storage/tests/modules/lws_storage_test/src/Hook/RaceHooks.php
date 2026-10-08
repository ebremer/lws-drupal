<?php

declare(strict_types=1);

namespace Drupal\lws_storage_test\Hook;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\State\StateInterface;
use Drupal\lws_storage\Entity\LwsResourceInterface;

/**
 * Simulates a concurrent create that takes a name at the last moment.
 */
final class RaceHooks {

  /**
   * The state key with the name another request takes first.
   */
  public const STATE = 'lws_storage_test.race';

  public function __construct(
    private readonly StateInterface $state,
    private readonly Connection $database,
    private readonly UuidInterface $uuid,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_presave() for lws_resource.
   *
   * Inserts a sibling with the same name just before the resource is saved,
   * after its name was found free.
   */
  #[Hook('lws_resource_presave')]
  public function presave(LwsResourceInterface $resource): void {
    $name = $resource->getName();
    if (!$resource->isNew() || $name !== $this->state->get(self::STATE)) {
      return;
    }
    $path = $resource->getParent()?->getPath() . $name;
    $this->database->insert('lws_resource')->fields([
      'uuid' => $this->uuid->generate(),
      'storage' => $resource->getLwsStorageId(),
      'parent' => $resource->getParent()?->id(),
      'name' => $name,
      'path' => $path,
      'path_hash' => hash('sha256', $path),
      'kind' => str_ends_with($name, '/') ? 'container' : 'data',
      'version' => 1,
      'created' => 0,
      'changed' => 0,
    ])->execute();
  }

}
