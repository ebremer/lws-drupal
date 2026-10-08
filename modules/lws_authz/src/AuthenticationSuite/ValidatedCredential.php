<?php

declare(strict_types=1);

namespace Drupal\lws_authz\AuthenticationSuite;

/**
 * What a valid authentication credential establishes (LWS Core §4.1).
 */
final class ValidatedCredential {

  /**
   * Constructs a validated credential.
   *
   * @param string $subject
   *   The agent: the "sub" of the access token.
   * @param string $issuer
   *   The issuer of the credential.
   * @param string $client
   *   The client: the "client_id" of the access token.
   * @param int $expiresAt
   *   When the credential expires, as a Unix time. No access token issued for
   *   it outlives it.
   */
  public function __construct(
    public readonly string $subject,
    public readonly string $issuer,
    public readonly string $client,
    public readonly int $expiresAt,
  ) {}

}
