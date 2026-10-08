<?php

declare(strict_types=1);

namespace Drupal\lws\Http;

use Drupal\lws\Lws;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds RFC 9457 problem details responses.
 */
final class ProblemResponse {

  /**
   * A problem details response for an HTTP status.
   *
   * @param int $status
   *   The HTTP status code.
   * @param string|null $detail
   *   An explanation safe to show the client, if any.
   * @param string|null $instance
   *   The URI of the resource the request addressed, if it has one.
   * @param array<string, string|list<string>> $headers
   *   Further response headers, such as Allow.
   */
  public static function create(int $status, ?string $detail = NULL, ?string $instance = NULL, array $headers = []): Response {
    $body = [
      'type' => 'about:blank',
      'title' => Response::$statusTexts[$status] ?? 'Error',
      'status' => $status,
    ];
    if ($detail !== NULL && $detail !== '') {
      $body['detail'] = $detail;
    }
    if ($instance !== NULL) {
      $body['instance'] = $instance;
    }
    $headers['Content-Type'] = Lws::MEDIA_TYPE_PROBLEM;
    $headers['Cache-Control'] = 'no-store';
    return new Response(
      json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
      $status,
      $headers,
    );
  }

}
