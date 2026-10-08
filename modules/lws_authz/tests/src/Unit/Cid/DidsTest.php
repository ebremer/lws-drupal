<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_authz\Unit\Cid;

use Drupal\lws_authz\Cid\Dids;
use Drupal\lws_authz\Cid\VerificationMethods;
use Drupal\Tests\UnitTestCase;
use Ebremer\Lws\Auth\DidKey;
use Ebremer\Lws\Auth\SigningKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests DID syntax, did:key documents and did:web URLs.
 */
#[CoversClass(Dids::class)]
#[Group('lws')]
final class DidsTest extends UnitTestCase {

  /**
   * Tests reading the method of a DID.
   */
  public function testMethod(): void {
    $this->assertSame('key', Dids::method('did:key:zDnaerx9CtbPJ1q36T5Ln5wYt3MQYeGRG5ehnPAmxcf5mDZpv'));
    $this->assertSame('web', Dids::method('did:web:example.com%3A3000:user:alice'));
    $this->assertSame('example', Dids::method('did:example:123'));
    // DID URLs, and what is no DID at all.
    $this->assertNull(Dids::method('did:key:z6Mk#z6Mk'));
    $this->assertNull(Dids::method('did:web:example.com/path'));
    $this->assertNull(Dids::method('did:Key:z6Mk'));
    $this->assertNull(Dids::method('did:key:'));
    $this->assertNull(Dids::method('https://example.com'));
  }

  /**
   * Tests the document a did:key expands to, for both key types.
   */
  public function testDidKeyDocument(): void {
    foreach ([SigningKey::generateP256(), SigningKey::generateEd25519()] as $key) {
      $did = DidKey::did($key->publicKey);
      $document = Dids::didKeyDocument($did);
      $this->assertSame($did, $document['id']);
      $methods = VerificationMethods::authentication($document, $did);
      $this->assertCount(1, $methods);
      $this->assertSame(DidKey::keyId($key->publicKey), $methods[0]->id);
      $this->assertTrue($methods[0]->key->equals($key->publicKey));
    }
  }

  /**
   * Tests that did:keys of other key types, or badly encoded, are refused.
   */
  public function testInvalidDidKey(): void {
    foreach (['did:key:z6Mk', 'did:key:zQ3s-not-base58', 'did:web:example.com', 'did:key:abc'] as $did) {
      try {
        Dids::didKeyDocument($did);
        $this->fail($did);
      }
      catch (\InvalidArgumentException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  /**
   * Tests the URLs of did:web documents.
   */
  #[DataProvider('didWebProvider')]
  public function testDidWebUrl(string $did, ?string $url): void {
    if ($url === NULL) {
      $this->expectException(\InvalidArgumentException::class);
    }
    $this->assertSame($url, Dids::didWebUrl($did));
  }

  /**
   * Data provider for testDidWebUrl().
   *
   * @return array<string, array{string, string|null}>
   *   DIDs and their document URLs; NULL for an invalid did:web.
   */
  public static function didWebProvider(): array {
    return [
      'domain' => ['did:web:w3c-ccg.github.io', 'https://w3c-ccg.github.io/.well-known/did.json'],
      'path' => ['did:web:w3c-ccg.github.io:user:alice', 'https://w3c-ccg.github.io/user/alice/did.json'],
      'port' => ['did:web:example.com%3A3000:user:alice', 'https://example.com:3000/user/alice/did.json'],
      'upper case host' => ['did:web:Example.COM', 'https://example.com/.well-known/did.json'],
      'IPv4 address' => ['did:web:127.0.0.1', NULL],
      'bad port' => ['did:web:example.com%3A99999', NULL],
      'empty segment' => ['did:web:example.com::alice', NULL],
      'bad label' => ['did:web:-example.com', NULL],
      'not did:web' => ['did:key:z6Mk', NULL],
    ];
  }

}
