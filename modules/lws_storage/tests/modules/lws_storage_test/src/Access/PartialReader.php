<?php

declare(strict_types=1);

namespace Drupal\lws_storage_test\Access;

use Drupal\lws\Access\AccessDecisionInterface;
use Drupal\lws\Access\Action;
use Drupal\lws\Access\AgentAccessScopeInterface;
use Drupal\lws\Access\Decision;
use Drupal\lws\Access\ResourceContext;
use Drupal\lws\Agent\RequestingAgent;
use Drupal\lws\Storage\StorageRef;

/**
 * A policy that lets one agent read all but resources named "secret...".
 */
final class PartialReader implements AccessDecisionInterface {

  /**
   * The agent with partial read access.
   */
  public const READER = 'https://id.example/reader';

  public function __construct(
    private readonly AccessDecisionInterface $inner,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function decide(RequestingAgent $agent, Action $action, ResourceContext $resource): Decision {
    if ($agent->subject !== self::READER) {
      return $this->inner->decide($agent, $action, $resource);
    }
    return $action === Action::Read && self::readable($resource) ? Decision::Permit : Decision::Deny;
  }

  /**
   * {@inheritdoc}
   */
  public function forAgent(RequestingAgent $agent, StorageRef $storage): AgentAccessScopeInterface {
    if ($agent->subject !== self::READER) {
      return $this->inner->forAgent($agent, $storage);
    }
    return new class implements AgentAccessScopeInterface {

      /**
       * {@inheritdoc}
       */
      public function readsSubtree(ResourceContext $container): bool {
        return FALSE;
      }

      /**
       * {@inheritdoc}
       */
      public function mayRead(ResourceContext $resource): bool {
        return PartialReader::readable($resource);
      }

    };
  }

  /**
   * Whether the reader may read a resource.
   */
  public static function readable(ResourceContext $resource): bool {
    return !str_contains(basename($resource->uri), 'secret');
  }

}
