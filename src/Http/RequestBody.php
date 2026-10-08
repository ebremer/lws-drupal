<?php

declare(strict_types=1);

namespace Drupal\lws\Http;

use Drupal\Component\Utility\Bytes;
use Symfony\Component\HttpFoundation\Request;

/**
 * A request body, as a stream, with the length its Content-Length announced.
 *
 * Content is written as it arrives, so a body that ends early, because the
 * client went away, would otherwise be stored as if it were whole. Knowing
 * the length also lets a body that is too large be refused before any of it
 * is read.
 */
final class RequestBody {

  /**
   * Creates a request body.
   *
   * @param resource $stream
   *   The body, readable.
   * @param int|null $length
   *   The bytes its Content-Length announced; NULL without one.
   */
  public function __construct(
    public readonly mixed $stream,
    public readonly ?int $length,
  ) {}

  /**
   * The body of a request.
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   400 for a malformed Content-Length; 413 for a POST larger than PHP's
   *   post_max_size, whose bytes PHP would never hand over.
   */
  public static function fromRequest(Request $request): self {
    $header = $request->headers->get('Content-Length');
    $length = NULL;
    if ($header !== NULL) {
      if (preg_match('/^\s*(\d{1,18})\s*$/', $header, $match) !== 1) {
        throw LwsHttpException::badRequest('The Content-Length is not a number of bytes.');
      }
      $length = (int) $match[1];
    }
    if ($length !== NULL && $request->isMethod('POST')) {
      $limit = self::postMaxSize();
      if ($limit > 0 && $length > $limit) {
        throw LwsHttpException::contentTooLarge($limit);
      }
    }
    $stream = $request->getContent(TRUE);
    assert(is_resource($stream));
    return new self($stream, $length);
  }

  /**
   * The whole of a small body, read no further than a limit.
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   413 when it is larger than the limit; 400 when it is shorter than its
   *   Content-Length.
   */
  public static function read(Request $request, int $limit): string {
    $body = self::fromRequest($request);
    if ($body->length !== NULL && $body->length > $limit) {
      throw LwsHttpException::contentTooLarge($limit);
    }
    $content = stream_get_contents($body->stream, $limit + 1);
    if ($content === FALSE) {
      throw LwsHttpException::badRequest('The request body could not be read.');
    }
    if (strlen($content) > $limit) {
      throw LwsHttpException::contentTooLarge($limit);
    }
    $body->checkLength(strlen($content));
    return $content;
  }

  /**
   * Refuses a body that ended before its Content-Length.
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   400 when the bytes read differ from those announced.
   */
  public function checkLength(int $read): void {
    if ($this->length !== NULL && $read !== $this->length) {
      throw LwsHttpException::badRequest(sprintf('The body has %d bytes, not the %d its Content-Length announced.', $read, $this->length));
    }
  }

  /**
   * PHP's post_max_size, in bytes; 0 for no limit.
   */
  public static function postMaxSize(): int {
    return (int) Bytes::toNumber((string) ini_get('post_max_size'));
  }

}
