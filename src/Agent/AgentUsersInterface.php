<?php

declare(strict_types=1);

namespace Drupal\lws\Agent;

/**
 * The Drupal users LWS agents act as (DESIGN.md §7.5).
 *
 * Provided by lws_agent_users, as the service with this interface's name.
 * Without it, agents are not Drupal users (D4), and nothing calls this.
 */
interface AgentUsersInterface {

  /**
   * What an agent is to this site as a Drupal user.
   *
   * @param \Drupal\lws\Agent\RequestingAgent $agent
   *   An agent.
   * @param bool $provision
   *   Whether an agent that has no account may get one, where the site
   *   provisions accounts: only for a request the agent makes itself, with a
   *   valid access token.
   *
   * @return \Drupal\lws\Agent\AgentUser|null
   *   NULL if the agent acts as no user.
   */
  public function find(RequestingAgent $agent, bool $provision = FALSE): ?AgentUser;

}
