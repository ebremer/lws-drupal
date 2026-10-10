<?php

declare(strict_types=1);

namespace Drupal\lws_projection\Access;

use Drupal\lws\Access\AccessDecisionInterface;
use Drupal\lws\Access\Action;
use Drupal\lws\Access\AgentAccessScopeInterface;
use Drupal\lws\Access\Decision;
use Drupal\lws\Access\ResourceContext;
use Drupal\lws\Agent\RequestingAgent;
use Drupal\lws\Storage\StorageRef;
use Drupal\lws_projection\Projections;

/**
 * Refuses every write to a projected storage, its controllers' too.
 *
 * Its content is Drupal's, which the Projector writes past the access
 * decision. Reading, and Control, which manages who may read, are decided as
 * for any storage.
 */
final class ReadOnlyProjections implements AccessDecisionInterface {

  public function __construct(
    private readonly AccessDecisionInterface $inner,
    private readonly Projections $projections,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function decide(RequestingAgent $agent, Action $action, ResourceContext $resource): Decision {
    if (in_array($action, [Action::Create, Action::Modify, Action::Delete], TRUE) && $this->projections->isProjected($resource->storage->id)) {
      return Decision::Deny;
    }
    return $this->inner->decide($agent, $action, $resource);
  }

  /**
   * {@inheritdoc}
   */
  public function forAgent(RequestingAgent $agent, StorageRef $storage): AgentAccessScopeInterface {
    return $this->inner->forAgent($agent, $storage);
  }

}
