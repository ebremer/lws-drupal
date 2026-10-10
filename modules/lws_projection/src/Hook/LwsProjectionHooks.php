<?php

declare(strict_types=1);

namespace Drupal\lws_projection\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\lws_projection\Entity\ProjectionInterface;
use Drupal\lws_projection\Projections;
use Drupal\lws_projection\Projector;
use Drupal\user\RoleInterface;

/**
 * Keeps projections up to date as content, projections and access change.
 */
final class LwsProjectionHooks {

  public function __construct(
    private readonly Projector $projector,
    private readonly Projections $projections,
  ) {}

  /**
   * Implements hook_entity_insert().
   */
  #[Hook('entity_insert')]
  public function entityInsert(EntityInterface $entity): void {
    $this->projector->changed($entity);
  }

  /**
   * Implements hook_entity_update().
   */
  #[Hook('entity_update')]
  public function entityUpdate(EntityInterface $entity): void {
    $this->projector->changed($entity);
  }

  /**
   * Implements hook_entity_delete().
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity): void {
    $this->projector->deleted($entity);
  }

  /**
   * Implements hook_ENTITY_TYPE_insert() for lws_projection.
   *
   * Its storage is made at once; its content comes with the sync, on cron.
   */
  #[Hook('lws_projection_insert')]
  public function projectionInsert(EntityInterface $projection): void {
    if ($projection instanceof ProjectionInterface) {
      $this->projector->prepare($projection);
      $this->projector->enqueue($projection);
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_update() for lws_projection.
   */
  #[Hook('lws_projection_update')]
  public function projectionUpdate(EntityInterface $projection): void {
    $this->projectionInsert($projection);
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for lws_projection.
   *
   * The storage stays, with what was projected, as an ordinary storage.
   */
  #[Hook('lws_projection_delete')]
  public function projectionDelete(EntityInterface $projection): void {
    $this->projections->setStorageId((string) $projection->id(), NULL);
  }

  /**
   * Implements hook_ENTITY_TYPE_update() for user_role.
   *
   * What a visitor may see depends on the anonymous role's permissions.
   */
  #[Hook('user_role_update')]
  public function userRoleUpdate(EntityInterface $role): void {
    if ($role->id() === RoleInterface::ANONYMOUS_ID) {
      $this->projector->enqueueAll();
    }
  }

}
