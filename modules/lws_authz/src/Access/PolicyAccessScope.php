<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Access;

use Drupal\lws\Access\Action;
use Drupal\lws\Access\AgentAccessScopeInterface;
use Drupal\lws\Access\ResourceContext;
use Drupal\lws\Agent\RequestingAgent;
use Drupal\lws\Storage\StorageRef;
use Drupal\lws_authz\Policy\PolicyEvaluator;
use Ebremer\Lws\ResourceType;

/**
 * What one agent may read in one storage, for container listings and searches.
 *
 * The agent's read policies are loaded once; each question is then answered
 * in memory.
 */
final class PolicyAccessScope implements AgentAccessScopeInterface {

  /**
   * Constructs the scope.
   *
   * @param \Drupal\lws\Agent\RequestingAgent $agent
   *   The agent.
   * @param \Drupal\lws\Storage\StorageRef $storage
   *   The storage.
   * @param list<\Drupal\lws_authz\Policy\AccessPolicy> $policies
   *   The policies that let the agent read something in the storage.
   * @param int $now
   *   The time of the request.
   * @param bool $controls
   *   Whether the agent controls the storage, and may read everything.
   */
  public function __construct(
    private readonly RequestingAgent $agent,
    private readonly StorageRef $storage,
    private readonly array $policies,
    private readonly int $now,
    private readonly bool $controls,
  ) {}

  /**
   * {@inheritdoc}
   *
   * True when a policy lets the agent read resources of every kind below the
   * container whatever their format or types.
   */
  public function readsSubtree(ResourceContext $container): bool {
    if ($this->controls) {
      return TRUE;
    }
    foreach ($this->policies as $policy) {
      if ($policy->targetType !== ResourceType::STORAGE_RESOURCE || !PolicyEvaluator::covers($policy, $container)) {
        continue;
      }
      foreach ($policy->constraints as $constraint) {
        if (PolicyEvaluator::dependsOnResource($constraint)) {
          continue 2;
        }
      }
      if (PolicyEvaluator::holds($policy->constraints, Action::Read, $this->agent, $container, $this->now)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * A policy covers the resources it targets and everything below them
   * (PolicyEvaluator::covers()); one that targets the storage, everything.
   */
  public function readableTargets(): ?array {
    if ($this->controls) {
      return NULL;
    }
    $targets = [];
    foreach ($this->policies as $policy) {
      if (in_array($this->storage->uri, $policy->targetValues, TRUE)) {
        return NULL;
      }
      array_push($targets, ...$policy->targetValues);
    }
    return array_values(array_unique($targets));
  }

  /**
   * {@inheritdoc}
   */
  public function mayRead(ResourceContext $resource): bool {
    if ($this->controls) {
      return TRUE;
    }
    foreach ($this->policies as $policy) {
      if (PolicyEvaluator::permits($policy, Action::Read, $this->agent, $resource, $this->now)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
