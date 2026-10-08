<?php

declare(strict_types=1);

namespace Drupal\lws\Http;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\PrivateKey;

/**
 * Opaque, signed keyset cursors for paginated listings (DESIGN.md §5.6).
 *
 * A cursor names the last key of the previous page, so a page starts after
 * it however the listing changed meanwhile. It is signed for one listing:
 * a cursor that was altered, or made for another listing, such as a
 * container deleted and created again at the same URI, does not decode.
 */
final class PaginationCursor {

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
   */
  public function encode(string $scope, string $after): string {
    $payload = self::base64url(json_encode(['a' => $after], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    return $payload . '.' . $this->signature($scope, $payload);
  }

  /**
   * Decodes a cursor.
   *
   * @param string $scope
   *   What the cursor must be for.
   * @param string $cursor
   *   The cursor, as a client sent it.
   *
   * @return string|null
   *   The key the page starts after; NULL for a cursor that is malformed,
   *   altered or for another scope.
   */
  public function decode(string $scope, string $cursor): ?string {
    $parts = explode('.', $cursor);
    if (count($parts) !== 2 || !hash_equals($this->signature($scope, $parts[0]), $parts[1])) {
      return NULL;
    }
    $json = base64_decode(strtr($parts[0], '-_', '+/'), TRUE);
    $data = $json === FALSE ? NULL : json_decode($json, TRUE);
    return is_array($data) && is_string($data['a'] ?? NULL) ? $data['a'] : NULL;
  }

  /**
   * The signature of a payload for a scope.
   */
  private function signature(string $scope, string $payload): string {
    return substr(Crypt::hmacBase64($scope . "\n" . $payload, $this->privateKey->get() . 'lws-cursor'), 0, 22);
  }

  /**
   * Base64url without padding.
   */
  private static function base64url(string $bytes): string {
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
  }

}
