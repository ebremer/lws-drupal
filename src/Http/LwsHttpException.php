<?php

declare(strict_types=1);

namespace Drupal\lws\Http;

use Drupal\lws\Routing\LwsArea;
use Drupal\lws\Routing\LwsTarget;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An HTTP error whose message is safe to show the client.
 *
 * LwsExceptionSubscriber puts the message of these exceptions, and of no
 * others, in the "detail" member of the problem details it returns.
 */
final class LwsHttpException extends HttpException {

  /**
   * Creates a 400 Bad Request error.
   */
  public static function badRequest(string $detail): self {
    return new self(400, $detail);
  }

  /**
   * Creates a 403 Forbidden error.
   *
   * For an agent with no valid token it becomes a 401 challenge.
   */
  public static function forbidden(string $detail): self {
    return new self(403, $detail);
  }

  /**
   * Creates a 429 Too Many Requests error.
   *
   * @param int $retryAfter
   *   The seconds after which the client may try again.
   */
  public static function tooManyRequests(int $retryAfter): self {
    return new self(429, 'Too many requests; try again later.', NULL, ['Retry-After' => (string) $retryAfter]);
  }

  /**
   * Creates a 429 Too Many Requests error for a limit an agent has reached.
   *
   * Unlike a rate limit, waiting does not help: the agent must give up
   * something first, such as one of its subscriptions.
   */
  public static function limitReached(string $detail): self {
    return new self(429, $detail);
  }

  /**
   * Creates a 404 Not Found error.
   */
  public static function notFound(string $detail = ''): self {
    return new self(404, $detail);
  }

  /**
   * Creates a 406 Not Acceptable error.
   *
   * @param list<string> $offered
   *   The media types the resource is available in.
   */
  public static function notAcceptable(array $offered): self {
    return new self(406, 'Available as ' . implode(', ', $offered) . '.');
  }

  /**
   * Creates a 409 Conflict error.
   */
  public static function conflict(string $detail): self {
    return new self(409, $detail);
  }

  /**
   * Creates a 412 Precondition Failed error.
   */
  public static function preconditionFailed(): self {
    return new self(412, 'The resource does not match the conditions of the request.');
  }

  /**
   * Creates a 413 Content Too Large error.
   */
  public static function contentTooLarge(int $limit): self {
    return new self(413, sprintf('The content is larger than %d bytes.', $limit));
  }

  /**
   * Creates a 415 Unsupported Media Type error.
   *
   * @param string $detail
   *   Why.
   * @param list<string> $acceptPatch
   *   The patch formats the resource takes, for Accept-Patch.
   */
  public static function unsupportedMediaType(string $detail, array $acceptPatch = []): self {
    return new self(415, $detail, NULL, $acceptPatch === [] ? [] : ['Accept-Patch' => implode(', ', $acceptPatch)]);
  }

  /**
   * Creates a 415 error for a QUERY in a format the target does not accept.
   *
   * @param list<string> $accepted
   *   The query formats it accepts, for Accept-Query (RFC 10008 §3).
   */
  public static function unsupportedQueryFormat(array $accepted): self {
    return new self(415, 'This query format is not supported. Accept-Query lists those that are.', NULL, ['Accept-Query' => implode(', ', $accepted)]);
  }

  /**
   * Creates a 422 Unprocessable Content error.
   */
  public static function unprocessable(string $detail): self {
    return new self(422, $detail);
  }

  /**
   * Creates a 428 Precondition Required error.
   */
  public static function preconditionRequired(): self {
    return new self(428, 'This storage requires If-Match on changes.');
  }

  /**
   * Creates a 507 Insufficient Storage error.
   */
  public static function insufficientStorage(): self {
    return new self(507, 'The storage quota does not allow this.');
  }

  /**
   * Creates a 503 Service Unavailable error.
   */
  public static function serviceUnavailable(string $detail): self {
    return new self(503, $detail);
  }

  /**
   * The error for a target at which nothing can exist.
   */
  public static function forUnaddressable(LwsTarget $target): self {
    return $target->area === LwsArea::Malformed
      ? self::badRequest((string) $target->error)
      : self::notFound();
  }

}
