<?php

declare(strict_types=1);

namespace Drupal\lws\Http;

/**
 * Formats Web Linking (RFC 8288) header values.
 */
final class LinkHeader {

  /**
   * Formats one link.
   *
   * @param string $target
   *   The target URI, already percent-encoded.
   * @param string $rel
   *   The relation type: a registered name or a URI.
   * @param array<string, string> $params
   *   Further target attributes, such as
   *   ['type' => 'application/linkset+json'].
   */
  public static function format(string $target, string $rel, array $params = []): string {
    $value = '<' . $target . '>; rel="' . $rel . '"';
    foreach ($params as $name => $param) {
      $value .= '; ' . $name . '="' . addcslashes($param, '"\\') . '"';
    }
    return $value;
  }

}
