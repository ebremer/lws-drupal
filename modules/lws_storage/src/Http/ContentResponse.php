<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Http;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Serves a data resource's bytes with its stored media type, unchanged.
 *
 * Symfony, and then PHP's default_charset, add "charset=UTF-8" to any text/*
 * type without a charset, which would misstate content that is not UTF-8.
 * Byte ranges, If-Range and HEAD are handled by BinaryFileResponse, except
 * for the length of a 416, and for ranges of content in a stream that cannot
 * seek, such as one of a remote stream wrapper: those are reached by reading
 * past the bytes before them.
 */
final class ContentResponse extends BinaryFileResponse {

  /**
   * {@inheritdoc}
   */
  public function prepare(Request $request): static {
    $type = $this->headers->get('Content-Type');
    parent::prepare($request);
    if ($type !== NULL && $this->headers->has('Content-Type')) {
      $this->headers->set('Content-Type', $type);
    }
    // BinaryFileResponse leaves the file's length on a 416, which has no
    // body: clients would wait for bytes that never come.
    if ($this->getStatusCode() === 416) {
      $this->headers->set('Content-Length', '0');
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function sendHeaders(?int $statusCode = NULL): static {
    // PHP applies default_charset when the Content-Type header is set.
    $charset = ini_get('default_charset');
    ini_set('default_charset', '');
    try {
      parent::sendHeaders($statusCode);
    }
    finally {
      ini_set('default_charset', (string) $charset);
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   *
   * BinaryFileResponse seeks to the start of a range, and sends the content
   * from its start if the stream cannot seek. Here a range of such a stream
   * starts after its leading bytes are read and dropped.
   */
  public function sendContent(): static {
    if ($this->offset === 0 || $this->maxlen === 0 || !$this->isSuccessful()) {
      return parent::sendContent();
    }
    $file = @fopen($this->file->getPathname(), 'rb');
    if ($file === FALSE) {
      throw new \RuntimeException(sprintf('The content at %s cannot be read.', $this->file->getPathname()));
    }
    try {
      if (fseek($file, $this->offset) !== 0) {
        for ($skip = $this->offset; $skip > 0 && !feof($file); $skip -= strlen($chunk)) {
          $chunk = fread($file, max(1, min($this->chunkSize, $skip)));
          if ($chunk === FALSE || $chunk === '') {
            return $this;
          }
        }
      }
      $out = fopen('php://output', 'wb');
      if ($out === FALSE) {
        return $this;
      }
      ignore_user_abort(TRUE);
      for ($length = $this->maxlen; $length !== 0 && !feof($file);) {
        $data = fread($file, max(1, $length > $this->chunkSize || $length < 0 ? $this->chunkSize : $length));
        if ($data === FALSE || $data === '' || fwrite($out, $data) === FALSE || connection_aborted()) {
          break;
        }
        if ($length > 0) {
          $length -= strlen($data);
        }
      }
      fclose($out);
    }
    finally {
      fclose($file);
    }
    return $this;
  }

}
