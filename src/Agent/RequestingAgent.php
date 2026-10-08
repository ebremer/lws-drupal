<?php

declare(strict_types=1);

namespace Drupal\lws\Agent;

/**
 * Who is making a request, as established by a validated access token.
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
   */
  public function __construct(
    public readonly ?string $subject = NULL,
    public readonly ?string $client = NULL,
    public readonly ?string $issuer = NULL,
    public readonly ?string $tokenId = NULL,
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

}
