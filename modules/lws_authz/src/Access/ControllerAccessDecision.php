<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Access;

use Drupal\lws\Access\AccessDecisionInterface;
use Drupal\lws\Access\Action;
use Drupal\lws\Access\Decision;
use Drupal\lws\Access\ResourceContext;
use Drupal\lws\Agent\RequestingAgent;

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
    return $agent->isAuthenticated() && in_array($agent->subject, $resource->storage->controllers, TRUE)
      ? Decision::Permit
      : Decision::Deny;
  }

}
