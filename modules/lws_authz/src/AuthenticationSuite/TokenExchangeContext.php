<?php

declare(strict_types=1);

namespace Drupal\lws_authz\AuthenticationSuite;

/**
 * What a suite needs to know about the token request it validates for.
 */
final class TokenExchangeContext {

  /**
   * Constructs a context.
   *
   * @param string $authorizationServer
   *   The issuer identifier of this authorization server, which a credential's
   *   audience must include.
   * @param int $now
   *   The current time, as a Unix time.
   * @param int $clockSkew
   *   The clock skew allowed when checking times, in seconds.
   */
  public function __construct(
    public readonly string $authorizationServer,
    public readonly int $now,
    public readonly int $clockSkew,
  ) {}

}
