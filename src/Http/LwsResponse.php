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
   *   The media type.
   * @param list<string> $links
   *   Link header values, one link each.
   * @param string|null $etag
   *   The entity tag, unquoted; it is always strong.
   */
  public static function json(array $body, string $contentType, array $links = [], ?string $etag = NULL): Response {
    $response = new Response(
      json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
      Response::HTTP_OK,
      ['Content-Type' => $contentType, 'Cache-Control' => self::CACHE_CONTROL],
    );
    if ($links !== []) {
      $response->headers->set('Link', $links);
    }
    if ($etag !== NULL) {
      $response->setEtag($etag);
    }
    return $response;
  }

}
