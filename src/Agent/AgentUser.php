<?php

declare(strict_types=1);

namespace Drupal\lws\Agent;

use Drupal\Core\Session\AccountInterface;

/**
 * An agent that acts as a Drupal user (AgentUsersInterface).
 */
final class AgentUser {

  /**
   * Constructs an agent user.
   *
   * @param \Drupal\lws\Agent\RequestingAgent $agent
   *   The agent, with the groups its user is in, and whether it is blocked or
   *   controls every storage (RequestingAgent::withStanding()).
   * @param int $uid
   *   The user's ID.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The user as the current user of the agent's requests; NULL when the
   *   user is blocked, whose requests stay anonymous to Drupal.
   */
  public function __construct(
    public readonly RequestingAgent $agent,
    public readonly int $uid,
    public readonly ?AccountInterface $account,
  ) {}

}
