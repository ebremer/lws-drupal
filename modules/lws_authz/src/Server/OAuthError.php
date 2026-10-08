<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Server;

/**
 * An OAuth 2.0 error response from the token endpoint (RFC 6749 §5.2).
 *
 * The message is the "error_description".
 */
final class OAuthError extends \RuntimeException {

  /**
   * Constructs an error.
   *
   * @param int $status
   *   The HTTP status code.
   * @param string $error
   *   The error code.
   * @param string $description
   *   A description for the client developer.
   * @param array<string, string> $headers
   *   Headers to add, such as Retry-After.
   */
  public function __construct(
    public readonly int $status,
    public readonly string $error,
    string $description,
    public readonly array $headers = [],
  ) {
    parent::__construct($description);
  }

  /**
   * A 400 with "invalid_request".
   */
  public static function invalidRequest(string $description): self {
    return new self(400, 'invalid_request', $description);
  }

}
