<?php

declare(strict_types=1);

namespace Drupal\lws\Http;

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

}
