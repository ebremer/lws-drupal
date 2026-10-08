<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Content;

/**
 * Content written to the file system, not yet referenced by a resource.
 */
final class StoredContent {

  /**
   * Constructs stored content.
   *
   * @param string $uri
   *   The file URI.
   * @param int $size
   *   The size in bytes.
   * @param string $sha256
   *   The SHA-256 digest, base64url-encoded.
   */
  public function __construct(
    public readonly string $uri,
    public readonly int $size,
    public readonly string $sha256,
  ) {}

}
