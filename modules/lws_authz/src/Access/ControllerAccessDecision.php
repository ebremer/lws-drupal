<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Access;

use Drupal\lws\Access\AccessDecisionInterface;
use Drupal\lws\Access\Action;
use Drupal\lws\Access\AgentAccessScopeInterface;
use Drupal\lws\Access\Decision;
use Drupal\lws\Access\ResourceContext;
use Drupal\lws\Agent\RequestingAgent;
use Drupal\lws\Storage\StorageRef;

/**
 * The policy decision point until access policies arrive (step A3).
 *
 * A storage's controllers may do anything in it, and nobody else may do
 * anything: every storage is private.
 */
final class ControllerAccessDecision implements AccessDecisionInterface {

  /**
   * {@inheritdoc}
   */
  public function decide(RequestingAgent $agent, Action $action, ResourceContext $resource): Decision {
    return self::controls($agent, $resource->storage) ? Decision::Permit : Decision::Deny;
  }

  /**
   * {@inheritdoc}
   */
  public function forAgent(RequestingAgent $agent, StorageRef $storage): AgentAccessScopeInterface {
    $controls = self::controls($agent, $storage);
    return new class($controls) implements AgentAccessScopeInterface {

      public function __construct(private readonly bool $controls) {}

      /**
       * {@inheritdoc}
       */
      public function readsSubtree(ResourceContext $container): bool {
        return $this->controls;
      }

      /**
       * {@inheritdoc}
       */
      public function mayRead(ResourceContext $resource): bool {
        return $this->controls;
      }

    };
  }

  /**
   * Whether an agent is a controller of a storage.
   */
  private static function controls(RequestingAgent $agent, StorageRef $storage): bool {
    return $agent->isAuthenticated() && in_array($agent->subject, $storage->controllers, TRUE);
  }

}
