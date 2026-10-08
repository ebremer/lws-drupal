<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Token;

use Ebremer\Lws\Auth\VerificationKey;

/**
 * The signature verification keys of a JSON Web Key Set (RFC 7517 §5).
 *
 * Only public parts of the keys the PHP LWS client can verify with are kept:
 * EC P-256 and P-384, and Ed25519. Keys of other types, keys for encryption,
 * and keys whose "alg" does not match their type are skipped.
 */
final class JsonWebKeySet {

  /**
   * Constructs a key set.
   *
   * @param list<array{kid: string|null, key: \Ebremer\Lws\Auth\VerificationKey}> $keys
   *   The keys, with their key IDs.
   */
  private function __construct(
    private readonly array $keys,
  ) {}

  /**
   * Parses a JWK Set.
   *
   * @param array<mixed>|string $jwks
   *   The key set, decoded or as JSON.
   *
   * @throws \InvalidArgumentException
   *   When it is not a JWK Set.
   */
  public static function parse(array|string $jwks): self {
    if (is_string($jwks)) {
      try {
        $jwks = json_decode($jwks, TRUE, 16, JSON_THROW_ON_ERROR);
      }
      catch (\JsonException $e) {
        throw new \InvalidArgumentException('The key set is not JSON: ' . $e->getMessage(), 0, $e);
      }
    }
    if (!is_array($jwks) || !is_array($jwks['keys'] ?? NULL) || !array_is_list($jwks['keys'])) {
      throw new \InvalidArgumentException('The key set has no "keys" array.');
    }
    $keys = [];
    foreach ($jwks['keys'] as $jwk) {
      if (!is_array($jwk) || ($jwk['use'] ?? 'sig') !== 'sig') {
        continue;
      }
      try {
        $key = VerificationKey::fromJwk($jwk);
      }
      catch (\InvalidArgumentException) {
        continue;
      }
      if (isset($jwk['alg']) && (!is_string($jwk['alg']) || !$key->supports($jwk['alg']))) {
        continue;
      }
      $keys[] = ['kid' => is_string($jwk['kid'] ?? NULL) ? $jwk['kid'] : NULL, 'key' => $key];
    }
    return new self($keys);
  }

  /**
   * The keys that may have signed a token.
   *
   * @param string|null $kid
   *   The token's "kid": when given, only keys with that ID qualify.
   * @param string $algorithm
   *   The token's "alg".
   *
   * @return list<\Ebremer\Lws\Auth\VerificationKey>
   *   The candidate keys.
   */
  public function candidates(?string $kid, string $algorithm): array {
    $candidates = [];
    foreach ($this->keys as $entry) {
      if ($entry['key']->supports($algorithm) && ($kid === NULL || $entry['kid'] === $kid)) {
        $candidates[] = $entry['key'];
      }
    }
    return $candidates;
  }

  /**
   * Whether the set has a key with an ID.
   */
  public function hasKeyId(string $kid): bool {
    return in_array($kid, array_column($this->keys, 'kid'), TRUE);
  }

  /**
   * The number of keys.
   */
  public function count(): int {
    return count($this->keys);
  }

  /**
   * The key set as a JWK Set, with public members only.
   *
   * @return array{keys: list<array<string, string>>}
   *   The JWK Set.
   */
  public function toArray(): array {
    $keys = [];
    foreach ($this->keys as $entry) {
      $jwk = $entry['key']->jwk();
      if ($entry['kid'] !== NULL) {
        $jwk['kid'] = $entry['kid'];
      }
      // An RSA key whose JWK named no algorithm verifies any RSA one, which
      // an "alg" member would narrow.
      if ($entry['key']->keyType() !== 'RSA') {
        $jwk['alg'] = $entry['key']->algorithm();
      }
      $jwk['use'] = 'sig';
      $keys[] = $jwk;
    }
    return ['keys' => $keys];
  }

}
