<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Server;

/**
 * An access token the token endpoint issued.
 */
final class IssuedToken {

  /**
   * Constructs an issued token.
   *
   * @param string $accessToken
   *   The token.
   * @param int $expiresIn
   *   Its lifetime, in seconds.
   */
  public function __construct(
    public readonly string $accessToken,
    public readonly int $expiresIn,
  ) {}

}
