<?php

declare(strict_types=1);

namespace Drupal\lws\Outbound;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * A response sink that stops accepting data past a size limit.
 *
 * A short write makes cURL abort the transfer, so an oversized body is never
 * downloaded in full.
 */
final class CappedStream implements StreamInterface {

  use StreamDecoratorTrait;

  /**
   * Whether more than the limit was offered.
   */
  public bool $overflowed = FALSE;

  /**
   * The bytes written so far.
   */
  private int $written = 0;

  public function __construct(
    private readonly StreamInterface $stream,
    private readonly int $limit,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function write(string $string): int {
    if ($this->written + strlen($string) > $this->limit) {
      $this->overflowed = TRUE;
      return 0;
    }
    $this->written += strlen($string);
    return $this->stream->write($string);
  }

}
