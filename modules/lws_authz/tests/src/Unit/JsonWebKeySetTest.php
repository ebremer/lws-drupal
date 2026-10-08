<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_authz\Unit;

use Drupal\lws_authz\Token\JsonWebKeySet;
use Drupal\Tests\UnitTestCase;
use Ebremer\Lws\Auth\SigningKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests parsing JSON Web Key Sets.
 */
#[CoversClass(JsonWebKeySet::class)]
#[Group('lws')]
final class JsonWebKeySetTest extends UnitTestCase {

  /**
   * Tests which keys are kept, and that only public members survive.
   */
  public function testParse(): void {
    $p256 = SigningKey::generateP256();
    $ed25519 = SigningKey::generateEd25519();
    $keys = JsonWebKeySet::parse([
      'keys' => [
        // A private key: only its public members are kept.
        $p256->jwk() + ['kid' => 'a'],
        $ed25519->publicKey->jwk() + ['kid' => 'b', 'use' => 'sig', 'alg' => 'EdDSA'],
        // Skipped: an RSA key too small, an encryption key, an "alg" its type
        // cannot have, junk.
        ['kty' => 'RSA', 'kid' => 'rsa', 'n' => 'AQAB', 'e' => 'AQAB'],
        $p256->publicKey->jwk() + ['kid' => 'enc', 'use' => 'enc'],
        $p256->publicKey->jwk() + ['kid' => 'wrong-alg', 'alg' => 'ES384'],
        'not a key',
      ],
    ]);
    $this->assertSame(2, $keys->count());
    $this->assertTrue($keys->hasKeyId('a'));
    $this->assertFalse($keys->hasKeyId('rsa'));

    $array = $keys->toArray();
    $this->assertSame(['kty', 'crv', 'x', 'y', 'kid', 'alg', 'use'], array_keys($array['keys'][0]));
    $this->assertSame('ES256', $array['keys'][0]['alg']);
    // What toArray() returns parses to the same keys.
    $this->assertCount(1, JsonWebKeySet::parse(json_encode($array, JSON_THROW_ON_ERROR))->candidates('a', 'ES256'));
  }

  /**
   * Tests choosing the keys that may have signed a token.
   */
  public function testCandidates(): void {
    $first = SigningKey::generateP256();
    $second = SigningKey::generateP256();
    $keys = JsonWebKeySet::parse([
      'keys' => [
        $first->publicKey->jwk() + ['kid' => 'first'],
        $second->publicKey->jwk(),
      ],
    ]);
    $this->assertCount(1, $keys->candidates('first', 'ES256'));
    $this->assertTrue($keys->candidates('first', 'ES256')[0]->equals($first->publicKey));
    // A token without "kid" may have been signed by any key of its algorithm.
    $this->assertCount(2, $keys->candidates(NULL, 'ES256'));
    $this->assertSame([], $keys->candidates(NULL, 'EdDSA'));
    $this->assertSame([], $keys->candidates('other', 'ES256'));
  }

  /**
   * Tests RSA keys, which may name one algorithm or be for any RSA one.
   */
  public function testRsa(): void {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    $this->assertNotFalse($key);
    $details = openssl_pkey_get_details($key);
    $this->assertIsArray($details);
    $b64 = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    $public = ['kty' => 'RSA', 'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e'])];
    $keys = JsonWebKeySet::parse([
      'keys' => [
        $public + ['kid' => 'any'],
        $public + ['kid' => 'rs256', 'alg' => 'RS256', 'use' => 'sig'],
        // Skipped: Keycloak's encryption key, by its use and by its "alg".
        $public + ['kid' => 'enc', 'use' => 'enc', 'alg' => 'RSA-OAEP'],
        $public + ['kid' => 'oaep', 'alg' => 'RSA-OAEP'],
      ],
    ]);
    $this->assertSame(2, $keys->count());
    $this->assertCount(1, $keys->candidates('any', 'PS512'));
    $this->assertCount(2, $keys->candidates(NULL, 'RS256'));
    $this->assertSame([], $keys->candidates('rs256', 'PS256'));
    $this->assertSame([], $keys->candidates(NULL, 'ES256'));
    // What toArray() returns keeps each key's algorithms.
    $array = $keys->toArray();
    $this->assertArrayNotHasKey('alg', $array['keys'][0]);
    $this->assertSame('RS256', $array['keys'][1]['alg']);
    $again = JsonWebKeySet::parse($array);
    $this->assertCount(1, $again->candidates('any', 'PS256'));
    $this->assertSame([], $again->candidates('rs256', 'PS256'));
  }

  /**
   * Tests documents that are not key sets.
   */
  public function testInvalid(): void {
    foreach (['not JSON', '{"keys":{"a":{}}}', '{"kty":"EC"}', '[]'] as $json) {
      try {
        JsonWebKeySet::parse($json);
        $this->fail("Parsed $json");
      }
      catch (\InvalidArgumentException) {
        $this->addToAssertionCount(1);
      }
    }
  }

}
