<?php

declare(strict_types=1);

namespace Drupal\lws_identity\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\lws_identity\AgentDocuments;
use Drupal\lws_identity\Entity\LwsAgentKeyInterface;
use Drupal\user\UserInterface;

/**
 * Who may see a user's agent and manage its keys.
 *
 * Administrators of agents, for any user; and users with "manage own lws
 * agent keys", for their own agent while they have one.
 */
final class AgentKeysAccess {

  /**
   * Checks access to a user's agent page and key forms.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   * @param \Drupal\user\UserInterface $user
   *   The user whose agent it is.
   * @param \Drupal\lws_identity\Entity\LwsAgentKeyInterface|null $lws_agent_key
   *   The key a form acts on, which must be that agent's.
   */
  public static function access(AccountInterface $account, UserInterface $user, ?LwsAgentKeyInterface $lws_agent_key = NULL): AccessResultInterface {
    if ($lws_agent_key !== NULL && (int) $lws_agent_key->getOwnerId() !== (int) $user->id()) {
      return AccessResult::forbidden()->addCacheableDependency($lws_agent_key);
    }
    $admin = AccessResult::allowedIfHasPermission($account, 'administer lws agents');
    if ($admin->isAllowed()) {
      return $admin;
    }
    return AccessResult::allowedIf(
      (int) $account->id() === (int) $user->id()
      && $account->hasPermission('manage own lws agent keys')
      && AgentDocuments::hasAgent($user),
    )->cachePerPermissions()->cachePerUser()->addCacheableDependency($user);
  }

}
