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
 * for the length of a 416.
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

}
