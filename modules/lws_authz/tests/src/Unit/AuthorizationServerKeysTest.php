<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_authz\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Site\Settings;
use Drupal\lws\Outbound\HostResolverInterface;
use Drupal\lws\Outbound\OutboundHttp;
use Drupal\lws_authz\Entity\TrustedAuthorizationServerInterface;
use Drupal\lws_authz\Token\AccessTokenValidator;
use Drupal\lws_authz\Token\AuthorizationServerKeys;
use Drupal\lws_authz\Token\InvalidTokenException;
use Drupal\lws_authz\Token\KeysUnavailableException;
use Drupal\Tests\UnitTestCase;
use Ebremer\Lws\Auth\Jwt;
use Ebremer\Lws\Auth\SigningKey;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;

/**
 * Tests discovering, caching and rotating an authorization server's keys.
 */
#[CoversClass(AuthorizationServerKeys::class)]
#[CoversClass(AccessTokenValidator::class)]
#[Group('lws')]
final class AuthorizationServerKeysTest extends UnitTestCase {

  private const ISSUER = 'https://as.example/tenant';

  private const METADATA = 'https://as.example/.well-known/lws-configuration/tenant';

  private const JWKS = 'https://as.example/tenant/jwks';

  private const STORAGE = 'https://storage.example/lws/alice/';

  /**
   * The responses the mock server gives, in order.
   */
  private MockHandler $mock;

  /**
   * The requests sent.
   *
   * @var list<array{request: \Psr\Http\Message\RequestInterface, options: array<string, mixed>}>
   */
  private array $sent = [];

  /**
   * The current time.
   */
  private int $now;

  /**
   * The keys under test.
   */
  private AuthorizationServerKeys $keys;

  /**
   * The validator under test.
   */
  private AccessTokenValidator $validator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->now = time();
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturnCallback(fn () => $this->now);
    $time->method('getCurrentTime')->willReturnCallback(fn () => $this->now);

    $this->mock = new MockHandler();
    $stack = HandlerStack::create($this->mock);
    $stack->push(fn (callable $handler) => function (RequestInterface $request, array $options) use ($handler) {
      $this->sent[] = ['request' => $request, 'options' => $options];
      return $handler($request, $options);
    });
    $resolver = $this->createMock(HostResolverInterface::class);
    $resolver->method('resolve')->willReturn(['93.184.215.14']);
    $http = new OutboundHttp(new Client(['handler' => $stack]), $resolver, new Settings([]));

    // Flood control with a memory: each event is allowed once per window.
    $flood = new class($this) implements FloodInterface {

      /**
       * Registered events.
       *
       * @var array<string, list<int>>
       */
      public array $events = [];

      public function __construct(private readonly AuthorizationServerKeysTest $test) {}

      /**
       * {@inheritdoc}
       */
      public function register($name, $window = 3600, $identifier = NULL): void {
        $this->events[$name . $identifier][] = $this->test->now();
      }

      /**
       * {@inheritdoc}
       */
      public function clear($name, $identifier = NULL): void {
        unset($this->events[$name . $identifier]);
      }

      /**
       * {@inheritdoc}
       */
      public function isAllowed($name, $threshold, $window = 3600, $identifier = NULL): bool {
        $recent = array_filter($this->events[$name . $identifier] ?? [], fn (int $t) => $t > $this->test->now() - $window);
        return count($recent) < $threshold;
      }

      /**
       * {@inheritdoc}
       */
      public function garbageCollection(): void {}

    };
    $this->keys = new AuthorizationServerKeys($http, new MemoryBackend($time), $flood, $time, new NullLogger());
    $this->validator = new AccessTokenValidator($this->keys, $time, $this->getConfigFactoryStub(['lws_authz.settings' => ['clock_skew' => 60]]), new NullLogger());
  }

  /**
   * The current time, for the flood control double.
   */
  public function now(): int {
    return $this->now;
  }

  /**
   * A trusted server.
   */
  private function server(?string $jwks = NULL): TrustedAuthorizationServerInterface {
    $server = $this->createMock(TrustedAuthorizationServerInterface::class);
    $server->method('id')->willReturn('test');
    $server->method('getIssuer')->willReturn(self::ISSUER);
    $server->method('getJwks')->willReturn($jwks);
    return $server;
  }

  /**
   * Queues the metadata and key set responses.
   *
   * @param array<string, \Ebremer\Lws\Auth\SigningKey> $keys
   *   Key IDs and the keys.
   */
  private function serve(array $keys): void {
    $jwks = [];
    foreach ($keys as $kid => $key) {
      $jwks[] = $key->publicKey->jwk() + ['kid' => $kid];
    }
    $this->mock->append(
      $this->metadata(['issuer' => self::ISSUER, 'jwks_uri' => self::JWKS]),
      new Response(200, [], json_encode(['keys' => $jwks], JSON_THROW_ON_ERROR)),
    );
  }

  /**
   * A metadata response with a token endpoint.
   *
   * @param array<string, string> $members
   *   Further members.
   */
  private function metadata(array $members): Response {
    return new Response(200, [], json_encode($members + ['token_endpoint' => self::ISSUER . '/token'], JSON_THROW_ON_ERROR));
  }

  /**
   * A valid token signed by a key.
   */
  private function token(SigningKey $key, string $kid): string {
    return Jwt::sign(['typ' => 'at+jwt', 'kid' => $kid], [
      'iss' => self::ISSUER,
      'sub' => 'https://id.example/alice',
      'client_id' => 'https://app.example/id',
      'aud' => self::STORAGE,
      'iat' => $this->now,
      'exp' => $this->now + 300,
      'jti' => 'j1',
    ], $key);
  }

  /**
   * Tests discovery through the metadata, with RFC 8414 path insertion.
   */
  public function testDiscovery(): void {
    $key = SigningKey::generateP256();
    $this->serve(['k1' => $key]);
    $agent = $this->validator->validate($this->token($key, 'k1'), $this->server(), self::STORAGE);
    $this->assertSame('https://id.example/alice', $agent->subject);
    $this->assertSame(self::ISSUER, $agent->issuer);
    $this->assertSame([self::METADATA, self::JWKS], array_map(static fn (array $t): string => (string) $t['request']->getUri(), $this->sent));

    // Cached: no further requests.
    $this->validator->validate($this->token($key, 'k1'), $this->server(), self::STORAGE);
    $this->assertCount(2, $this->sent);
  }

  /**
   * Tests that a rotated-in key is fetched, at most once a minute.
   */
  public function testRotation(): void {
    $old = SigningKey::generateP256();
    $new = SigningKey::generateP256();
    $this->serve(['old' => $old]);
    $this->validator->validate($this->token($old, 'old'), $this->server(), self::STORAGE);

    // The server rotates: a token names a key the cache lacks.
    $this->serve(['new' => $new]);
    $this->validator->validate($this->token($new, 'new'), $this->server(), self::STORAGE);
    $this->assertCount(4, $this->sent);

    // The old key is gone with the refresh.
    $this->assertInvalid($this->token($old, 'old'), 'signature does not verify');

    // Unknown key IDs force no further fetch within the window...
    $this->assertInvalid($this->token(SigningKey::generateP256(), 'bogus'), 'signature does not verify');
    $this->assertCount(4, $this->sent);

    // ...but do after it.
    $this->now += AuthorizationServerKeys::REFRESH_WINDOW + 1;
    $this->serve(['new' => $new]);
    $this->assertInvalid($this->token(SigningKey::generateP256(), 'bogus'), 'signature does not verify');
    $this->assertCount(6, $this->sent);
  }

  /**
   * Tests that pinned keys are never fetched.
   */
  public function testPinned(): void {
    $key = SigningKey::generateP256();
    $server = $this->server(json_encode(['keys' => [$key->publicKey->jwk() + ['kid' => 'pinned']]], JSON_THROW_ON_ERROR));
    $this->validator->validate($this->token($key, 'pinned'), $server, self::STORAGE);
    $this->assertInvalidFor($server, $this->token(SigningKey::generateP256(), 'other'), 'signature does not verify');
    $this->assertSame([], $this->sent);
  }

  /**
   * Tests metadata that cannot be used, and that failures are cached.
   */
  public function testDiscoveryFailures(): void {
    // Metadata for another issuer (RFC 8414 §3.3).
    $this->mock->append($this->metadata(['issuer' => 'https://evil.example', 'jwks_uri' => self::JWKS]));
    $this->assertUnavailable('is for the issuer https://evil.example');

    // Remembered for a minute: nothing is fetched.
    $this->assertUnavailable('is for the issuer https://evil.example');
    $this->assertCount(1, $this->sent);

    $this->now += AuthorizationServerKeys::FAILURE_TTL + 1;
    $this->mock->append($this->metadata(['issuer' => self::ISSUER]));
    $this->assertUnavailable('has no jwks_uri');

    $this->now += AuthorizationServerKeys::FAILURE_TTL + 1;
    $this->mock->append(new Response(503));
    $this->assertUnavailable('answered 503');

    // A failure means every token is rejected.
    $this->assertInvalid($this->token(SigningKey::generateP256(), 'k1'), 'keys are unavailable');
  }

  /**
   * Asserts that the keys of the test server cannot be obtained.
   */
  private function assertUnavailable(string $reason): void {
    try {
      $this->keys->keySet($this->server());
      $this->fail('Obtained the keys');
    }
    catch (KeysUnavailableException $e) {
      $this->assertStringContainsString($reason, $e->getMessage());
    }
  }

  /**
   * Asserts that a token is rejected by the test server.
   */
  private function assertInvalid(string $token, string $reason): void {
    $this->assertInvalidFor($this->server(), $token, $reason);
  }

  /**
   * Asserts that a token is rejected.
   */
  private function assertInvalidFor(TrustedAuthorizationServerInterface $server, string $token, string $reason): void {
    try {
      $this->validator->validate($token, $server, self::STORAGE);
      $this->fail('Accepted the token');
    }
    catch (InvalidTokenException $e) {
      $this->assertStringContainsString($reason, $e->getMessage());
    }
  }

}
