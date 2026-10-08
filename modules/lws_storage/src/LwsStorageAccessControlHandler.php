<?php

declare(strict_types=1);

namespace Drupal\lws_storage;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\lws_storage\Entity\LwsStorageInterface;

/**
 * Who may administer a storage through Drupal (DESIGN.md §5.7).
 *
 * Storage administrators may do anything. A storage's owner, with "manage own
 * lws storages", sees it in the list and manages who may access it, but
 * changes nothing else. None of this is access to the storage's resources:
 * that is LWS policy, which agents' access tokens are checked against.
 */
final class LwsStorageAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    $admin = AccessResult::allowedIfHasPermission($account, 'administer lws storages');
    if ($operation !== 'view' || !$entity instanceof LwsStorageInterface) {
      return $admin;
    }
    return $admin->orIf(self::owns($account, $entity));
  }

  /**
   * {@inheritdoc}
   *
   * Only administrators create storages, for now: whether users may make
   * their own is an open question (DESIGN.md §14, Q2).
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   * @param array<string, mixed> $context
   *   The context.
   * @param string|null $entity_bundle
   *   The bundle; storages have none.
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'administer lws storages');
  }

  /**
   * Whether an account manages a storage as its owner.
   */
  public static function owns(AccountInterface $account, LwsStorageInterface $storage): AccessResultInterface {
    return AccessResult::allowedIf(
      $account->hasPermission('manage own lws storages') && $account->id() > 0 && (int) $account->id() === $storage->getOwnerId(),
    )->cachePerPermissions()->cachePerUser()->addCacheableDependency($storage);
  }

}
