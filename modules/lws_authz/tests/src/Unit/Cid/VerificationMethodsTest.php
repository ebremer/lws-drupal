<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_authz\Unit\Cid;

use Drupal\lws_authz\Cid\VerificationMethods;
use Drupal\Tests\UnitTestCase;
use Ebremer\Lws\Auth\DidKey;
use Ebremer\Lws\Auth\SigningKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests retrieving verification methods from controlled identifier documents.
 */
#[CoversClass(VerificationMethods::class)]
#[Group('lws')]
final class VerificationMethodsTest extends UnitTestCase {

  private const AGENT = 'https://id.example/agent';

  /**
   * The agent's key.
   */
  private SigningKey $key;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->key = SigningKey::generateP256();
  }

  /**
   * A JsonWebKey verification method of the agent.
   *
   * @param array<string, mixed> $overrides
   *   Members to add or replace.
   *
   * @return array<string, mixed>
   *   The method.
   */
  private function method(array $overrides = []): array {
    return $overrides + [
      'id' => self::AGENT . '#key-1',
      'type' => 'JsonWebKey',
      'controller' => self::AGENT,
      'publicKeyJwk' => $this->key->publicKey->jwk() + ['kid' => 'jwk-kid', 'alg' => 'ES256'],
    ];
  }

  /**
   * The IDs of the methods a document names for authentication.
   *
   * @param array<string, mixed> $document
   *   The document, without its "id".
   *
   * @return list<string>
   *   The method IDs.
   */
  private function ids(array $document): array {
    $methods = VerificationMethods::authentication(['id' => self::AGENT] + $document, self::AGENT);
    return array_map(static fn ($method) => $method->id, $methods);
  }

  /**
   * Tests embedded and referenced methods.
   */
  public function testEmbeddedAndReferenced(): void {
    $this->assertSame([self::AGENT . '#key-1'], $this->ids(['authentication' => [$this->method()]]));
    // A single method, not in an array.
    $this->assertSame([self::AGENT . '#key-1'], $this->ids(['authentication' => $this->method()]));
    // Referenced by its absolute ID, or by a fragment, from verificationMethod.
    foreach ([self::AGENT . '#key-1', '#key-1'] as $reference) {
      $this->assertSame([self::AGENT . '#key-1'], $this->ids([
        'verificationMethod' => [$this->method()],
        'authentication' => [$reference],
      ]));
    }
    // Relative IDs resolve against the document.
    $relative = $this->method(['id' => '#key-2', 'controller' => 'agent']);
    $this->assertSame([self::AGENT . '#key-2'], $this->ids(['authentication' => [$relative]]));
    // A reference to a method embedded in another relationship.
    $this->assertSame([self::AGENT . '#key-1'], $this->ids([
      'assertionMethod' => [$this->method()],
      'authentication' => ['#key-1'],
    ]));
    // A reference to nothing, and a reference into another document.
    $this->assertSame([], $this->ids(['authentication' => ['#missing']]));
    $this->assertSame([], $this->ids([
      'verificationMethod' => [$this->method()],
      'authentication' => ['https://other.example/agent#key-1'],
    ]));
  }

  /**
   * Tests the methods the subject cannot authenticate with.
   */
  public function testRefusedMethods(): void {
    // Listed, but only for assertions.
    $this->assertSame([], $this->ids([
      'verificationMethod' => [$this->method()],
      'assertionMethod' => ['#key-1'],
    ]));
    // Controlled by another identifier.
    $this->assertSame([], $this->ids(['authentication' => [$this->method(['controller' => 'https://other.example/agent'])]]));
    // Living in another document.
    $this->assertSame([], $this->ids(['authentication' => [$this->method(['id' => 'https://other.example/agent#key-1'])]]));
    // Without an ID.
    $this->assertSame([], $this->ids(['authentication' => [$this->method(['id' => NULL])]]));
    // A private key in the document.
    $this->assertSame([], $this->ids(['authentication' => [$this->method(['publicKeyJwk' => $this->key->jwk()])]]));
    // A JWK whose "alg" its key cannot have.
    $this->assertSame([], $this->ids(['authentication' => [$this->method(['publicKeyJwk' => $this->key->publicKey->jwk() + ['alg' => 'ES384']])]]));
    // Key types this site cannot verify with.
    $rsa = ['kty' => 'RSA', 'n' => 'AQAB', 'e' => 'AQAB'];
    $this->assertSame([], $this->ids(['authentication' => [$this->method(['publicKeyJwk' => $rsa])]]));
    $this->assertSame([], $this->ids(['authentication' => [$this->method(['type' => 'EcdsaSecp256k1VerificationKey2019'])]]));
    // An unreadable revocation time.
    $this->assertSame([], $this->ids(['authentication' => [$this->method(['revoked' => 'yesterday'])]]));
    $this->assertSame([], $this->ids(['authentication' => [$this->method(['expires' => '2000-01-01T00:00:00'])]]));
  }

  /**
   * Tests Multikey methods and the older types read the same way.
   */
  public function testKeyTypes(): void {
    $ed25519 = SigningKey::generateEd25519();
    $multibase = substr(DidKey::did($ed25519->publicKey), strlen('did:key:'));
    $methods = VerificationMethods::authentication([
      'id' => self::AGENT,
      'authentication' => [
        ['id' => '#multikey', 'type' => 'Multikey', 'controller' => self::AGENT, 'publicKeyMultibase' => $multibase],
        [
          'id' => '#ed2020',
          'type' => 'Ed25519VerificationKey2020',
          'controller' => self::AGENT,
          'publicKeyMultibase' => $multibase,
        ],
        $this->method(['id' => '#jwk2020', 'type' => 'JsonWebKey2020']),
        // Not base58btc.
        ['id' => '#bad', 'type' => 'Multikey', 'controller' => self::AGENT, 'publicKeyMultibase' => 'uAAAA'],
      ],
    ], self::AGENT);
    $this->assertSame([self::AGENT . '#multikey', self::AGENT . '#ed2020', self::AGENT . '#jwk2020'], array_map(static fn ($method) => $method->id, $methods));
    $this->assertTrue($methods[0]->key->equals($ed25519->publicKey));
    $this->assertSame('EdDSA', $methods[1]->key->algorithm());
    $this->assertTrue($methods[2]->key->equals($this->key->publicKey));
  }

  /**
   * Tests revocation and expiry times.
   */
  public function testRevokedAndExpired(): void {
    $methods = VerificationMethods::authentication([
      'id' => self::AGENT,
      'authentication' => [
        $this->method(['id' => '#revoked', 'revoked' => '2000-01-01T00:00:00Z']),
        $this->method(['id' => '#expires', 'expires' => '2100-01-01T00:00:00+01:00']),
      ],
    ], self::AGENT);
    $now = time();
    $this->assertSame('it was revoked', $methods[0]->inactiveReason($now));
    $this->assertNull($methods[1]->inactiveReason($now));
    $this->assertSame('it has expired', $methods[1]->inactiveReason(4102441200));
  }

  /**
   * Tests which method a "kid" selects.
   */
  public function testSelect(): void {
    $methods = VerificationMethods::authentication([
      'id' => self::AGENT,
      'authentication' => [
        $this->method(['id' => '#key-1']),
        $this->method(['id' => '#key%202', 'publicKeyJwk' => SigningKey::generateP256()->publicKey->jwk()]),
      ],
    ], self::AGENT);
    $this->assertSame(self::AGENT . '#key-1', VerificationMethods::select($methods, self::AGENT . '#key-1')?->id);
    $this->assertSame(self::AGENT . '#key-1', VerificationMethods::select($methods, 'jwk-kid')?->id);
    $this->assertSame(self::AGENT . '#key-1', VerificationMethods::select($methods, 'key-1')?->id);
    $this->assertSame(self::AGENT . '#key-1', VerificationMethods::select($methods, '#key-1')?->id);
    $this->assertSame(self::AGENT . '#key%202', VerificationMethods::select($methods, 'key 2')?->id);
    // No fallback to some key.
    $this->assertNull(VerificationMethods::select($methods, 'key-3'));
    $this->assertNull(VerificationMethods::select($methods, ''));
    $this->assertNull(VerificationMethods::select([], 'key-1'));
  }

  /**
   * Tests resolving references against a document's identifier.
   */
  public function testResolve(): void {
    $this->assertSame('https://id.example/agent#k', VerificationMethods::resolve('#k', 'https://id.example/agent'));
    $this->assertSame('https://id.example/card#k', VerificationMethods::resolve('#k', 'https://id.example/card#me'));
    $this->assertSame('https://id.example/keys/1', VerificationMethods::resolve('keys/1', 'https://id.example/agent'));
    $this->assertSame('did:key:z6Mk#z6Mk', VerificationMethods::resolve('#z6Mk', 'did:key:z6Mk'));
    $this->assertSame('did:web:x.example#k', VerificationMethods::resolve('did:web:x.example#k', 'https://id.example/agent'));
    $this->assertNull(VerificationMethods::resolve('keys/1', 'did:key:z6Mk'));
    $this->assertNull(VerificationMethods::resolve('', 'https://id.example/agent'));
  }

}
