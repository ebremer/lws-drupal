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
        // Skipped: RSA, an encryption key, an "alg" its type cannot have, junk.
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
