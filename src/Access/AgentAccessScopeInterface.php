<?php

declare(strict_types=1);

namespace Drupal\lws\Access;

/**
 * The policy decision point bound to one agent, for many resources.
 *
 * Container listings and searches ask it about every resource they show: it
 * works out once what applies to the agent and answers each question in
 * memory (DESIGN.md §6.5).
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

  /**
   * Where the agent may read anything, so that a search can look only there.
   *
   * The agent may read nothing outside these resources and the subtrees of
   * these containers, though not necessarily everything inside them:
   * mayRead() still decides each resource.
   *
   * @return list<string>|null
   *   The URIs of resources and containers, none if the agent may read
   *   nothing; NULL when there is no such bound, as when it may read
   *   anywhere in the storage.
   */
  public function readableTargets(): ?array;

}
