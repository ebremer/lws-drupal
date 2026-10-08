<?php

declare(strict_types=1);

namespace Drupal\lws\Access;

use Drupal\lws\Agent\RequestingAgent;
use Drupal\lws\Storage\StorageRef;

/**
 * The policy decision point.
 *
 * Implemented by lws_authz and used by lws_storage, which neither knows how
 * policy is stored nor needs to.
 */
interface AccessDecisionInterface {

  /**
   * Decides one operation on one resource. Never throws for "deny".
   *
   * @param \Drupal\lws\Agent\RequestingAgent $agent
   *   The requesting agent, possibly anonymous.
   * @param \Drupal\lws\Access\Action $action
   *   The operation. For Create, the resource is the target container.
   * @param \Drupal\lws\Access\ResourceContext $resource
   *   The resource.
   */
  public function decide(RequestingAgent $agent, Action $action, ResourceContext $resource): Decision;

  /**
   * A decider bound to one agent in one storage, for container listings.
   *
   * @param \Drupal\lws\Agent\RequestingAgent $agent
   *   The requesting agent, possibly anonymous.
   * @param \Drupal\lws\Storage\StorageRef $storage
   *   The storage.
   */
  public function forAgent(RequestingAgent $agent, StorageRef $storage): AgentAccessScopeInterface;

}
