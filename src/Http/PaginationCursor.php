<?php

declare(strict_types=1);

namespace Drupal\lws\Http;

use Drupal\Core\PrivateKey;

/**
 * Opaque, encrypted keyset cursors for paginated listings (DESIGN.md §5.6).
 *
 * A cursor names the last key of the previous page, so a page starts after
 * it however the listing changed meanwhile. It is encrypted and
 * authenticated for one listing (AES-256-GCM, with the listing as associated
 * data): a cursor that was altered, or made for another listing, such as a
 * container deleted and created again at the same URI, does not decode. The
 * key it holds may be that of a member the agent may not see, so nobody can
 * read it.
 *
 * The same key in the same listing always makes the same cursor, so clients
 * can tell that two links lead to one page. The IV is therefore synthetic, a
 * MAC of the listing and the key, as in SIV modes: a nonce repeats only with
 * its plaintext.
 */
final class PaginationCursor {

  private const CIPHER = 'aes-256-gcm';

  private const IV_BYTES = 12;

  private const TAG_BYTES = 16;

  public function __construct(
    private readonly PrivateKey $privateKey,
  ) {}

  /**
   * Encodes the key a page starts after.
   *
   * @param string $scope
   *   What the cursor is for, such as the UUID of a container.
   * @param string $after
   *   The last key of the previous page.
   *
   * @return string
   *   The cursor: base64url, safe in a query string.
   */
  public function encode(string $scope, string $after): string {
    $iv = substr(hash_hmac('sha256', $scope . "\0" . $after, $this->key('iv'), TRUE), 0, self::IV_BYTES);
    $tag = '';
    $ciphertext = openssl_encrypt($after, self::CIPHER, $this->key('cipher'), OPENSSL_RAW_DATA, $iv, $tag, $scope, self::TAG_BYTES);
    if ($ciphertext === FALSE) {
      throw new \RuntimeException('Pagination cursors cannot be encrypted.');
    }
    return rtrim(strtr(base64_encode($iv . $tag . $ciphertext), '+/', '-_'), '=');
  }

  /**
   * Decodes a cursor.
   *
   * @param string $scope
   *   What the cursor must be for.
   * @param string $cursor
   *   The cursor.
   *
   * @return string|null
   *   The key the page starts after; NULL if the cursor is malformed,
   *   altered or for another scope.
   */
  public function decode(string $scope, string $cursor): ?string {
    if (preg_match('/^[A-Za-z0-9_-]+$/', $cursor) !== 1) {
      return NULL;
    }
    $bytes = base64_decode(strtr($cursor, '-_', '+/'), TRUE);
    // Only the encoding encode() makes: extra characters may decode to the
    // same bytes, and a cursor names one page in one way.
    if ($bytes === FALSE || strlen($bytes) < self::IV_BYTES + self::TAG_BYTES || rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=') !== $cursor) {
      return NULL;
    }
    $after = openssl_decrypt(
      substr($bytes, self::IV_BYTES + self::TAG_BYTES),
      self::CIPHER,
      $this->key('cipher'),
      OPENSSL_RAW_DATA,
      substr($bytes, 0, self::IV_BYTES),
      substr($bytes, self::IV_BYTES, self::TAG_BYTES),
      $scope,
    );
    return is_string($after) ? $after : NULL;
  }

  /**
   * A key for cursors, derived from the site's private key.
   *
   * @param string $use
   *   What it is for: "cipher" or "iv".
   */
  private function key(string $use): string {
    return hash_hmac('sha256', 'lws-cursor-' . $use, $this->privateKey->get(), TRUE);
  }

}
