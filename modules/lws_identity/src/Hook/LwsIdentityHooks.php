<?php

declare(strict_types=1);

namespace Drupal\lws_identity\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\lws_identity\AgentKeys;
use Drupal\lws_identity\Provisioner;
use Drupal\user\UserInterface;

/**
 * Provisions storages as users get agents, and removes their keys with them.
 */
final class LwsIdentityHooks {

  public function __construct(
    private readonly Provisioner $provisioner,
    private readonly AgentKeys $keys,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_insert() for user.
   */
  #[Hook('user_insert')]
  public function userInsert(EntityInterface $user): void {
    if ($user instanceof UserInterface) {
      $this->provisioner->provisionIfNeeded($user);
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_update() for user.
   *
   * An account approved, unblocked or given the permission after it was
   * created gets its storage then.
   */
  #[Hook('user_update')]
  public function userUpdate(EntityInterface $user): void {
    if ($user instanceof UserInterface) {
      $this->provisioner->provisionIfNeeded($user);
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for user.
   *
   * The agent's keys go with it. A storage it controlled stays, with the
   * agent URI among its controllers, until an administrator deletes it.
   */
  #[Hook('user_delete')]
  public function userDelete(EntityInterface $user): void {
    $keys = $this->keys->keysOf((int) $user->id());
    if ($keys !== []) {
      $this->entityTypeManager->getStorage('lws_agent_key')->delete($keys);
    }
  }

}
