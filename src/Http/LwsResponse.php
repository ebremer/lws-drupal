<?php

declare(strict_types=1);

namespace Drupal\lws\Http;

use Symfony\Component\HttpFoundation\Response;

/**
 * Builds successful LWS responses.
 */
final class LwsResponse {

  /**
   * The Cache-Control value of LWS responses.
   *
   * It must not be Symfony's default ("no-cache, private"): for a response
   * with that value, core's FinishResponseSubscriber removes ETag,
   * Last-Modified and Vary, and LWS requires ETags (LWS Core §9.1, §9.3).
   * Symfony sorts the directives, so "private, no-cache" would also become
   * the default.
   */
  public const CACHE_CONTROL = 'private, no-cache, max-age=0';

  /**
   * A JSON response.
   *
   * @param array<string, mixed> $body
   *   The document.
   * @param string $contentType
   *   The Content-Type header value.
   * @param list<string> $links
   *   Link header values, one link each.
   * @param string|null $etag
   *   The entity tag, unquoted; it is always strong.
   * @param array<string, string> $headers
   *   Further headers, such as Vary.
   */
  public static function json(array $body, string $contentType, array $links = [], ?string $etag = NULL, array $headers = []): Response {
    $response = new Response(
      json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
      Response::HTTP_OK,
      ['Content-Type' => $contentType, 'Cache-Control' => self::CACHE_CONTROL] + $headers,
    );
    if ($links !== []) {
      $response->headers->set('Link', $links);
    }
    if ($etag !== NULL) {
      $response->setEtag($etag);
    }
    return $response;
  }

  /**
   * A response without a body, such as a 201, 204 or 304.
   *
   * @param int $status
   *   The status code.
   * @param list<string> $links
   *   Link header values, one link each.
   * @param string|null $etag
   *   The entity tag, unquoted; it is always strong.
   * @param array<string, string> $headers
   *   Further headers, such as Location.
   */
  public static function empty(int $status, array $links = [], ?string $etag = NULL, array $headers = []): Response {
    $response = new Response('', $status, ['Cache-Control' => self::CACHE_CONTROL] + $headers);
    if ($links !== []) {
      $response->headers->set('Link', $links);
    }
    if ($etag !== NULL) {
      $response->setEtag($etag);
    }
    return $response;
  }

  /**
   * A strong entity tag for a representation.
   *
   * @param string ...$parts
   *   What the representation depends on, such as its body and media type.
   */
  public static function etag(string ...$parts): string {
    $hash = hash('sha256', implode("\0", $parts), TRUE);
    return substr(rtrim(strtr(base64_encode($hash), '+/', '-_'), '='), 0, 22);
  }

}
