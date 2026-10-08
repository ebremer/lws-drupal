<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Policy;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lws\Storage\StorageRef;
use Drupal\lws_authz\Entity\LwsPolicyInterface;

/**
 * Stores the access policies of storages.
 */
final class PolicyStore {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * The policies of a storage for any of some assignees.
   *
   * @param int $storageId
   *   The storage.
   * @param list<string> $assignees
   *   The assignees.
   *
   * @return list<\Drupal\lws_authz\Policy\AccessPolicy>
   *   The policies.
   */
  public function forAssignees(int $storageId, array $assignees): array {
    $ids = $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('storage', $storageId)
      ->condition('assignee', $assignees, 'IN')
      ->execute();
    $policies = [];
    foreach ($this->storage()->loadMultiple($ids) as $entity) {
      assert($entity instanceof LwsPolicyInterface);
      $policy = $entity->toAccessPolicy();
      // The database may compare case-insensitively; URIs do not.
      if (in_array($policy->assignee, $assignees, TRUE)) {
        $policies[] = $policy;
      }
    }
    return $policies;
  }

  /**
   * All the policies of a storage, oldest first.
   *
   * @return array<int, \Drupal\lws_authz\Entity\LwsPolicyInterface>
   *   The policies, by ID.
   */
  public function forStorage(int $storageId): array {
    $ids = $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('storage', $storageId)
      ->sort('id')
      ->execute();
    $policies = [];
    foreach ($this->storage()->loadMultiple($ids) as $id => $entity) {
      assert($entity instanceof LwsPolicyInterface);
      $policies[(int) $id] = $entity;
    }
    return $policies;
  }

  /**
   * A policy of a storage by ID, if there is one.
   */
  public function load(int $storageId, int $id): ?LwsPolicyInterface {
    $policy = $this->storage()->load($id);
    return $policy instanceof LwsPolicyInterface && $policy->getStorageId() === $storageId ? $policy : NULL;
  }

  /**
   * Stores a policy.
   *
   * @param \Drupal\lws\Storage\StorageRef $storage
   *   The storage it applies in.
   * @param \Drupal\lws_authz\Policy\AccessPolicy $policy
   *   The policy, as the parser read it.
   * @param string $source
   *   Where it comes from: "admin", or "grant:{id}".
   * @param int $uid
   *   The Drupal user who made it; 0 for none, as for Drush or a grant.
   */
  public function add(StorageRef $storage, AccessPolicy $policy, string $source = 'admin', int $uid = 0): LwsPolicyInterface {
    $entity = $this->storage()->create(['storage' => $storage->id, 'source' => $source, 'uid' => $uid]);
    assert($entity instanceof LwsPolicyInterface);
    $entity->setAccessPolicy($policy)->save();
    return $entity;
  }

  /**
   * Deletes the policies of a storage, as when it is deleted.
   */
  public function deleteForStorage(int $storageId): void {
    $this->storage()->delete($this->forStorage($storageId));
  }

  /**
   * Deletes policies made by administrators that can no longer permit.
   *
   * Policies of access grants go with their grants.
   *
   * @return int
   *   How many were deleted.
   */
  public function purgeExpired(int $now): int {
    $ids = $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('source', 'admin')
      ->condition('not_after', $now, '<')
      ->range(0, 500)
      ->execute();
    $this->storage()->delete($this->storage()->loadMultiple($ids));
    return count($ids);
  }

  /**
   * The entity storage of policies.
   */
  private function storage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('lws_policy');
  }

}
