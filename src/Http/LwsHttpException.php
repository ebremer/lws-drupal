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
   * Creates a 404 Not Found error.
   */
  public static function notFound(string $detail = ''): self {
    return new self(404, $detail);
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
