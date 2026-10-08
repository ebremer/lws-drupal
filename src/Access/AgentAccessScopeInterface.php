<?php

declare(strict_types=1);

namespace Drupal\lws\Access;

/**
 * The policy decision point bound to one agent, for many resources.
 *
 * Container listings ask it about every member: it works out once what
 * applies to the agent and answers each question in memory (DESIGN.md §6.5).
 */
interface AgentAccessScopeInterface {

  /**
   * Whether the agent may read every resource below a container.
   *
   * Then a listing needs no check per member and can be a plain query.
   */
  public function readsSubtree(ResourceContext $container): bool;

  /**
   * Whether the agent may read a resource.
   */
  public function mayRead(ResourceContext $resource): bool;

}
