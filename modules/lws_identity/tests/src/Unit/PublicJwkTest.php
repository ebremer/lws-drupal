<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_identity\Unit;

use Drupal\Component\Transliteration\PhpTransliteration;
use Drupal\Tests\UnitTestCase;
use Drupal\lws_identity\AgentKeys;
use Drupal\lws_identity\InvalidAgentKeyException;
use Drupal\lws_identity\Provisioner;
use Ebremer\Lws\Auth\SigningKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests which keys an agent may have, and what is kept of them.
 */
#[Group('lws_identity')]
final class PublicJwkTest extends UnitTestCase {

  /**
   * The RSA key of RFC 7638 §3.1, and its thumbprint.
   */
  private const RFC7638_N = '0vx7agoebGcQSuuPiLJXZptN9nndrQmbXEps2aiAFbWhM78LhWx4cbbfAAtVT86zwu1RK7aPFFxuhDR1L6tSoc_BJECPebWKRXjBZCiFV4n3oknjhMstn64tZ_2W-5JsGY4Hc5n9yBXArwl93lqt7_RN5w6Cf0h4QyQ5v-65YGjQR0_FDW2QvzqY368QQMicAtaSqzs8KJZgnYb9c7d0zgdAZHzu6qMQvRL5hajrn1n91CbOpbISD08qNLyrdkt-bFTWhAI4vMQFh6WeZu0fM4lFd2NcRwr3XPksINHaQ-G_xBniIqbw0Ls1jF44-csFCur-kEgU8awapJzKnqDKgw';

  private const RFC7638_THUMBPRINT = 'NzbLsXh8uDCcd-6MNwXF4W_7noWXFZAfHkxZsRGC9Xs';

  /**
   * Tests the thumbprint against RFC 7638's example.
   */
  public function testThumbprint(): void {
    $jwk = ['kty' => 'RSA', 'n' => self::RFC7638_N, 'e' => 'AQAB', 'alg' => 'RS256', 'kid' => '2011-04-29'];
    $this->assertSame(self::RFC7638_THUMBPRINT, AgentKeys::thumbprint($jwk));
    // Its kid is kept, as is its alg; the members come in a fixed order.
    $this->assertSame(['kty' => 'RSA', 'n' => self::RFC7638_N, 'e' => 'AQAB', 'alg' => 'RS256', 'kid' => '2011-04-29'], AgentKeys::publicJwk(['e' => 'AQAB'] + $jwk));
    // Without a kid, the thumbprint is the key ID.
    unset($jwk['kid']);
    $this->assertSame(self::RFC7638_THUMBPRINT, AgentKeys::publicJwk($jwk)['kid']);
  }

  /**
   * Tests that only the public members, alg and kid are kept.
   */
  public function testKept(): void {
    $public = SigningKey::generateP256()->publicKey->jwk();
    $kept = AgentKeys::publicJwk($public + [
      'use' => 'sig',
      'key_ops' => ['verify'],
      'x5c' => ['MIIB'],
      'alg' => 'ES256',
      'ext' => TRUE,
    ]);
    $this->assertSame($public + ['alg' => 'ES256', 'kid' => AgentKeys::thumbprint($public)], $kept);
    $ed25519 = SigningKey::generateEd25519()->publicKey->jwk();
    $this->assertSame($ed25519 + ['kid' => 'k.1_~-'], AgentKeys::publicJwk($ed25519 + ['kid' => 'k.1_~-']));
  }

  /**
   * Tests keys that are refused.
   *
   * @param array<string, mixed> $jwk
   *   The submitted JWK.
   * @param string $reason
   *   Part of the message.
   */
  #[DataProvider('refusedProvider')]
  public function testRefused(array $jwk, string $reason): void {
    try {
      AgentKeys::publicJwk($jwk);
      $this->fail('The key was accepted.');
    }
    catch (InvalidAgentKeyException $e) {
      $this->assertStringContainsString($reason, $e->getMessage());
    }
  }

  /**
   * Keys that are refused, and why.
   *
   * @return array<string, array{array<string, mixed>, string}>
   *   The cases.
   */
  public static function refusedProvider(): array {
    $p256 = SigningKey::generateP256();
    $public = $p256->publicKey->jwk();
    return [
      'private key' => [$p256->jwk(), 'private members (d)'],
      'symmetric key' => [['kty' => 'oct', 'k' => 'c2VjcmV0'], 'private members (k)'],
      'key set' => [['keys' => [$public]], 'JWK Set'],
      'unsupported curve' => [['kty' => 'EC', 'crv' => 'secp256k1', 'x' => 'AA', 'y' => 'AA'], 'Unsupported EC curve'],
      'no key type' => [['crv' => 'P-256'], "'kty' is missing"],
      'encryption key' => [$public + ['use' => 'enc'], '"use" is not "sig"'],
      'no verify' => [$public + ['key_ops' => ['encrypt']], 'lack "verify"'],
      'wrong algorithm' => [$public + ['alg' => 'ES384'], 'must be one of'],
      'no algorithm' => [$public + ['alg' => 'none'], 'must be one of'],
      'kid with a slash' => [$public + ['kid' => 'a/b'], '"kid" must be'],
      'long kid' => [$public + ['kid' => str_repeat('k', 65)], '"kid" must be'],
      'numeric kid' => [$public + ['kid' => 7], '"kid" must be'],
    ];
  }

  /**
   * Tests decoding the JSON of a key.
   */
  public function testDecode(): void {
    $this->assertSame(['kty' => 'EC'], AgentKeys::decode('{"kty":"EC"}'));
    foreach (['not json' => 'not JSON', '[1, 2]' => 'not a JSON object', '"EC"' => 'not a JSON object'] as $json => $reason) {
      try {
        AgentKeys::decode($json);
        $this->fail('Decoded ' . $json);
      }
      catch (InvalidAgentKeyException $e) {
        $this->assertStringContainsString($reason, $e->getMessage());
      }
    }
  }

  /**
   * Tests the storage slugs user names suggest.
   */
  public function testSlugBase(): void {
    $transliteration = new PhpTransliteration();
    $this->assertSame('zoe-smith', Provisioner::slugBase('Zoë Smith', $transliteration));
    $this->assertSame('alice-o-brien', Provisioner::slugBase("Ålice_O'Brien", $transliteration));
    $this->assertSame('user', Provisioner::slugBase('---', $transliteration));
    $this->assertSame('a-b', Provisioner::slugBase('a' . str_repeat(' ', 70) . 'b', $transliteration));
    $this->assertSame(str_repeat('a', 56), Provisioner::slugBase(str_repeat('a', 80), $transliteration));
    // Cut to length, without a trailing hyphen.
    $this->assertSame(str_repeat('a', 55), Provisioner::slugBase(str_repeat('a', 55) . ' bcd', $transliteration));
  }

}
