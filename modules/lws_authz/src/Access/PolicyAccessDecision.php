<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Access;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\lws\Access\AccessDecisionInterface;
use Drupal\lws\Access\Action;
use Drupal\lws\Access\AgentAccessScopeInterface;
use Drupal\lws\Access\Decision;
use Drupal\lws\Access\ResourceContext;
use Drupal\lws\Agent\RequestingAgent;
use Drupal\lws\Storage\StorageRef;
use Drupal\lws_authz\Policy\AccessPolicy;
use Drupal\lws_authz\Policy\PolicyEvaluator;
use Drupal\lws_authz\Policy\PolicyStore;

/**
 * The policy decision point (DESIGN.md §6.5).
 *
 * A storage's controllers may do anything in it. Anyone else may do what an
 * access policy of the storage permits them, and nothing more: only
 * controllers have Control. Policies are read afresh for every request, so a
 * policy deleted takes effect on the next one.
 */
final class PolicyAccessDecision implements AccessDecisionInterface {

  public function __construct(
    private readonly PolicyStore $policies,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function decide(RequestingAgent $agent, Action $action, ResourceContext $resource): Decision {
    if (self::controls($agent, $resource->storage)) {
      return Decision::Permit;
    }
    if ($action === Action::Control) {
      return Decision::Deny;
    }
    $now = $this->time->getRequestTime();
    foreach ($this->policies->forAssignees($resource->storage->id, self::assignees($agent)) as $policy) {
      if (PolicyEvaluator::permits($policy, $action, $agent, $resource, $now)) {
        return Decision::Permit;
      }
    }
    return Decision::Deny;
  }

  /**
   * {@inheritdoc}
   */
  public function forAgent(RequestingAgent $agent, StorageRef $storage): AgentAccessScopeInterface {
    if (self::controls($agent, $storage)) {
      return new PolicyAccessScope($agent, $storage, [], $this->time->getRequestTime(), TRUE);
    }
    $readers = array_values(array_filter(
      $this->policies->forAssignees($storage->id, self::assignees($agent)),
      static fn (AccessPolicy $policy): bool => in_array(Action::Read->value, $policy->actions, TRUE),
    ));
    return new PolicyAccessScope($agent, $storage, $readers, $this->time->getRequestTime(), FALSE);
  }

  /**
   * The assignees whose policies apply to an agent.
   *
   * @return list<string>
   *   The public, and an authenticated agent itself and every authenticated
   *   agent.
   */
  private static function assignees(RequestingAgent $agent): array {
    return $agent->isAuthenticated()
      ? [AccessPolicy::PUBLIC, AccessPolicy::AUTHENTICATED, (string) $agent->subject]
      : [AccessPolicy::PUBLIC];
  }

  /**
   * Whether an agent is a controller of a storage.
   */
  private static function controls(RequestingAgent $agent, StorageRef $storage): bool {
    return $agent->isAuthenticated() && in_array($agent->subject, $storage->controllers, TRUE);
  }

}
