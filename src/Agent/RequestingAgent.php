<?php

declare(strict_types=1);

namespace Drupal\lws\Agent;

/**
 * Who is making a request, as established by a validated access token.
 *
 * With lws_agent_users, it also carries what the Drupal user the agent acts
 * as makes of it (AgentUsersInterface): the groups it is in, and whether it
 * is blocked or controls every storage. Without it those are never set.
 */
final class RequestingAgent {

  /**
   * Constructs a requesting agent.
   *
   * @param string|null $subject
   *   The agent URI (the token's "sub"); NULL when unauthenticated.
   * @param string|null $client
   *   The client URI (the token's "client_id").
   * @param string|null $issuer
   *   The authorization server that issued the token (its "iss").
   * @param string|null $tokenId
   *   The token's "jti", for audit only.
   * @param list<string> $groups
   *   The groups the site counts the agent in, as URIs that access policies
   *   can name as assignees: the roles of the Drupal user it acts as.
   * @param bool $blocked
   *   Whether the site bars the agent: the Drupal user it acts as is blocked.
   * @param bool $controlsEveryStorage
   *   Whether it may do anything in every storage, as their controllers may:
   *   the Drupal user it acts as may bypass LWS access policies.
   */
  public function __construct(
    public readonly ?string $subject = NULL,
    public readonly ?string $client = NULL,
    public readonly ?string $issuer = NULL,
    public readonly ?string $tokenId = NULL,
    public readonly array $groups = [],
    public readonly bool $blocked = FALSE,
    public readonly bool $controlsEveryStorage = FALSE,
  ) {}

  /**
   * The agent of a request that presented no valid token.
   */
  public static function anonymous(): self {
    return new self();
  }

  /**
   * Whether a valid token identified the agent.
   */
  public function isAuthenticated(): bool {
    return $this->subject !== NULL;
  }

  /**
   * The same agent, with what the Drupal user it acts as makes of it.
   *
   * @param list<string> $groups
   *   The groups it is in, as URIs.
   * @param bool $blocked
   *   Whether the site bars it.
   * @param bool $controlsEveryStorage
   *   Whether it controls every storage.
   */
  public function withStanding(array $groups, bool $blocked, bool $controlsEveryStorage): self {
    return new self($this->subject, $this->client, $this->issuer, $this->tokenId, $groups, $blocked, $controlsEveryStorage);
  }

}
