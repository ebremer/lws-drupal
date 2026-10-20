<?php

declare(strict_types=1);

namespace Drupal\lws\Http;

use Drupal\lws\Agent\Authentication;

/**
 * Formats Bearer challenges for WWW-Authenticate (RFC 6750 §3).
 */
final class BearerChallenge {

  /**
   * A Bearer challenge with its parameters as quoted strings.
   *
   * @param array<string, string|null> $params
   *   Parameter names and values, in order; NULL values are left out.
   */
  public static function format(array $params): string {
    $parts = [];
    foreach ($params as $name => $value) {
      if ($value !== NULL) {
        $parts[] = $name . '="' . addcslashes($value, '"\\') . '"';
      }
    }
    return 'Bearer' . ($parts === [] ? '' : ' ' . implode(', ', $parts));
  }

  /**
   * The challenge of a 401 in the LWS URL space.
   *
   * A conforming challenge carries "resource_metadata" (RFC 9728 §5.1), the
   * URL of the protected resource metadata of the URL the client requested,
   * which names the authorization server (LWS Core, Authorization Server
   * Discovery). While the outcome asks for it, "as_uri" and "realm" follow:
   * the drafts before 19 October 2026 required them instead, clients written
   * against those drafts still look for them, and the current ones let a
   * challenge carry other parameters. A rejected token adds its "error" and
   * "error_description" (RFC 6750 §3.1).
   *
   * @param string $resourceMetadata
   *   The URL of the protected resource metadata of the requested URL.
   * @param \Drupal\lws\Agent\Authentication $authentication
   *   The outcome of authenticating the request.
   */
  public static function unauthorized(string $resourceMetadata, Authentication $authentication): string {
    return self::format([
      'resource_metadata' => $resourceMetadata,
      'as_uri' => $authentication->legacyChallenge ? $authentication->issuer : NULL,
      'realm' => $authentication->legacyChallenge ? $authentication->storageUri : NULL,
      'error' => $authentication->error,
      'error_description' => $authentication->errorDescription,
    ]);
  }

}
