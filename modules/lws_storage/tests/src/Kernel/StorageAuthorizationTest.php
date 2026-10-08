<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_storage\Kernel;

use Drupal\lws_authz\Authentication\LwsAccount;
use Ebremer\Lws\Auth\SigningKey;
use Ebremer\Lws\Http\AuthChallenge;
use Ebremer\Lws\Http\WwwAuthenticate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests token validation and access at the storage (LWS Core §5.2.1, §5.2.4).
 *
 * Every 401 must carry a Bearer challenge with "as_uri" and "realm", and a
 * rejected token also an "error". A valid token without permission gets 403.
 */
#[Group('lws')]
#[RunTestsInSeparateProcesses]
final class StorageAuthorizationTest extends LwsStorageKernelTestBase {

  private const ALICE = 'https://id.example/alice';

  private const BOB = 'https://id.example/bob';

  private const STORAGE = self::BASE . '/lws/alice/';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->storages->createStorage('alice', 'Alice', [self::ALICE]);
  }

  /**
   * Sends a GET with a bearer token, or none.
   */
  private function get(string $path, ?string $token = NULL): Response {
    return $this->send('GET', $path, $token === NULL ? [] : ['Authorization' => 'Bearer ' . $token]);
  }

  /**
   * Asserts a 401 with a conforming challenge, and returns the challenge.
   */
  private function assertChallenge(Response $response, ?string $error = NULL, string $realm = self::STORAGE, string $asUri = self::ISSUER): AuthChallenge {
    $this->assertSame(401, $response->getStatusCode());
    $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));
    $challenges = WwwAuthenticate::parse((string) $response->headers->get('WWW-Authenticate'));
    $this->assertCount(1, $challenges);
    $challenge = $challenges[0];
    $this->assertTrue($challenge->isScheme('Bearer'));
    $this->assertSame($asUri, $challenge->asUri());
    $this->assertSame($realm, $challenge->realm());
    $this->assertSame($error, $challenge->error());
    $this->assertContains('<' . $realm . '>; rel="https://www.w3.org/ns/lws#storage"', $response->headers->all('link'));
    return $challenge;
  }

  /**
   * Tests that a request without a token is challenged.
   */
  public function testAnonymous(): void {
    $challenge = $this->assertChallenge($this->get('/lws/alice/root/'));
    $this->assertNull($challenge->errorDescription());
    $this->assertSame(401, $this->send('HEAD', '/lws/alice/root/')->getStatusCode());
    // The same for a resource that does not exist: the challenge reveals
    // nothing.
    $this->assertChallenge($this->get('/lws/alice/root/missing/'));
    // A scheme other than Bearer is no token.
    $this->assertChallenge($this->send('GET', '/lws/alice/root/', ['Authorization' => 'Basic YWxpY2U6c2VjcmV0']));
  }

  /**
   * Tests the controller's access.
   */
  public function testController(): void {
    $response = $this->get('/lws/alice/root/', $this->token(self::ALICE));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertFalse($response->headers->has('WWW-Authenticate'));
    $this->assertProblem($this->get('/lws/alice/root/missing/', $this->token(self::ALICE)), 404, self::STORAGE . 'root/missing/');

    // The agent is the current user, to Drupal an anonymous one.
    $account = $this->container->get('current_user')->getAccount();
    $this->assertInstanceOf(LwsAccount::class, $account);
    $this->assertSame(self::ALICE, $account->agent->subject);
    $this->assertSame('https://app.example/id', $account->agent->client);
    $this->assertTrue($account->isAnonymous());

    // An audience without the trailing slash also names the storage.
    $this->assertSame(200, $this->get('/lws/alice/root/', $this->token(self::ALICE, ['aud' => self::BASE . '/lws/alice']))->getStatusCode());
    // As does a single-valued array.
    $this->assertSame(200, $this->get('/lws/alice/root/', $this->token(self::ALICE, ['aud' => [self::STORAGE]]))->getStatusCode());
  }

  /**
   * Tests that a valid token without permission gets 403, not a challenge.
   */
  public function testNonController(): void {
    foreach (['/lws/alice/root/', '/lws/alice/root/missing/'] as $path) {
      $response = $this->get($path, $this->token(self::BOB));
      $this->assertProblem($response, 403, self::BASE . $path);
      $this->assertFalse($response->headers->has('WWW-Authenticate'), $path);
    }
  }

  /**
   * Tests the storage description, which needs no token.
   */
  public function testDescription(): void {
    $this->assertSame(200, $this->get('/lws/alice/')->getStatusCode());
    $this->assertSame(200, $this->get('/lws/alice/', $this->token(self::BOB))->getStatusCode());
    // A rejected token is rejected even where none is needed.
    $this->assertChallenge($this->get('/lws/alice/', $this->token(self::ALICE, ['exp' => time() - 3600])), 'invalid_token');
  }

  /**
   * Token faults, each of which makes a token invalid.
   *
   * @return array<string, array{array<string, mixed>, array<string, mixed>, string}>
   *   Claims to change, header parameters to change, and part of the error
   *   description.
   */
  public static function faults(): array {
    $now = time();
    return [
      'expired' => [['iat' => $now - 3900, 'exp' => $now - 3600], [], 'expired'],
      'expired beyond the skew' => [['exp' => $now - 61], [], 'expired'],
      'not yet valid' => [['nbf' => $now + 3600], [], 'not valid yet'],
      'issued in the future' => [['iat' => $now + 3600, 'exp' => $now + 3900], [], 'issued in the future'],
      'wrong audience' => [['aud' => 'https://not-this-storage.invalid/'], [], 'not for this storage'],
      'two audiences' => [['aud' => [self::STORAGE, 'https://another-storage.invalid/']], [], 'not for this storage'],
      'another storage' => [['aud' => self::BASE . '/lws/bob/'], [], 'not for this storage'],
      'no audience' => [['aud' => NULL], [], 'not for this storage'],
      'wrong issuer' => [['iss' => 'https://untrusted-issuer.invalid/'], [], 'not from this storage'],
      'no issuer' => [['iss' => NULL], [], 'not from this storage'],
      'unknown key' => [[], ['kid' => 'unknown'], 'signature does not verify'],
      'no type' => [[], ['typ' => NULL], '"typ"'],
      'plain JWT type' => [[], ['typ' => 'JWT'], '"typ"'],
      'critical parameters' => [[], ['crit' => ['exp']], 'critical'],
      'no subject' => [['sub' => NULL], [], '"sub"'],
      'subject not a URI' => [['sub' => 'alice'], [], '"sub"'],
      'no client' => [['client_id' => NULL], [], '"client_id"'],
      'no token ID' => [['jti' => NULL], [], '"jti"'],
      'no expiry' => [['exp' => NULL], [], '"exp"'],
      'no issue time' => [['iat' => NULL], [], '"iat"'],
      'string expiry' => [['exp' => (string) ($now + 300)], [], '"exp"'],
    ];
  }

  /**
   * Tests that each fault is answered with invalid_token.
   *
   * @param array<string, mixed> $claims
   *   Claims to change.
   * @param array<string, mixed> $header
   *   Header parameters to change.
   * @param string $description
   *   Part of the expected error description.
   */
  #[DataProvider('faults')]
  public function testInvalidToken(array $claims, array $header, string $description): void {
    $challenge = $this->assertChallenge($this->get('/lws/alice/root/', $this->token(self::ALICE, $claims, $header)), 'invalid_token');
    $this->assertStringContainsString($description, (string) $challenge->errorDescription());
  }

  /**
   * Tests faults in the signature itself.
   */
  public function testSignatureFaults(): void {
    $token = $this->token(self::ALICE);
    [$header, $claims, $signature] = explode('.', $token);

    // A corrupted signature.
    $corrupted = $header . '.' . $claims . '.' . strrev($signature);
    $this->assertChallenge($this->get('/lws/alice/root/', $corrupted), 'invalid_token');

    // Valid claims re-signed by a key nobody publishes, under the right kid.
    $this->assertChallenge($this->get('/lws/alice/root/', $this->token(self::ALICE, key: SigningKey::generateP256())), 'invalid_token');

    // "alg": "none", with and without a signature.
    $none = rtrim(strtr(base64_encode('{"alg":"none","typ":"at+jwt"}'), '+/', '-_'), '=');
    $this->assertChallenge($this->get('/lws/alice/root/', $none . '.' . $claims . '.'), 'invalid_token');
    $this->assertChallenge($this->get('/lws/alice/root/', $none . '.' . $claims . '.' . $signature), 'invalid_token');

    // HMAC, keyed with something public, such as the public key.
    $hs = rtrim(strtr(base64_encode('{"alg":"HS256","typ":"at+jwt","kid":"' . self::KID . '"}'), '+/', '-_'), '=');
    $mac = hash_hmac('sha256', $hs . '.' . $claims, (string) json_encode($this->signingKey->publicKey->jwk()), TRUE);
    $this->assertChallenge($this->get('/lws/alice/root/', $hs . '.' . $claims . '.' . rtrim(strtr(base64_encode($mac), '+/', '-_'), '=')), 'invalid_token');

    // Not a JWT at all.
    $this->assertChallenge($this->get('/lws/alice/root/', 'opaque-token'), 'invalid_token');
  }

  /**
   * Tests that the clock skew allowance applies.
   */
  public function testClockSkew(): void {
    $now = time();
    $this->assertSame(200, $this->get('/lws/alice/root/', $this->token(self::ALICE, ['exp' => $now - 30]))->getStatusCode());
    $early = $this->token(self::ALICE, ['iat' => $now + 30, 'nbf' => $now + 30]);
    $this->assertSame(200, $this->get('/lws/alice/root/', $early)->getStatusCode());
    $this->config('lws_authz.settings')->set('clock_skew', 0)->save();
    $this->assertChallenge($this->get('/lws/alice/root/', $this->token(self::ALICE, ['exp' => $now - 30])), 'invalid_token');
  }

  /**
   * Tests malformed Authorization headers.
   */
  public function testInvalidRequest(): void {
    foreach (['Bearer', 'Bearer ', 'Bearer two tokens', 'Bearer t*ken'] as $value) {
      $challenge = $this->assertChallenge($this->send('GET', '/lws/alice/root/', ['Authorization' => $value]), 'invalid_request');
      $this->assertNotNull($challenge->errorDescription(), $value);
    }
  }

  /**
   * Tests a storage that trusts its own authorization server.
   */
  public function testStorageAuthorizationServer(): void {
    $key = SigningKey::generateP256();
    $this->trustServer('other', 'https://other-as.example', $key, 'other-key');
    $this->storages->createStorage('carol', 'Carol', [self::ALICE], NULL, 'other');
    $realm = self::BASE . '/lws/carol/';

    $this->assertChallenge($this->get('/lws/carol/root/'), NULL, $realm, 'https://other-as.example');
    // A token from the site's default server is not from carol's.
    $this->assertChallenge($this->get('/lws/carol/root/', $this->token(self::ALICE, ['aud' => $realm])), 'invalid_token', $realm, 'https://other-as.example');
    $token = $this->token(self::ALICE, ['iss' => 'https://other-as.example', 'aud' => $realm], ['kid' => 'other-key'], $key);
    $this->assertSame(200, $this->get('/lws/carol/root/', $token)->getStatusCode());
    // And that token is no good at alice's storage.
    $this->assertChallenge($this->get('/lws/alice/root/', $token), 'invalid_token');

    $this->expectException(\InvalidArgumentException::class);
    $this->storages->createStorage('dave', 'Dave', [], NULL, 'missing');
  }

  /**
   * Tests a storage with no authorization server to send clients to.
   */
  public function testNoAuthorizationServer(): void {
    $server = $this->container->get('entity_type.manager')->getStorage('lws_trusted_as')->load('test');
    $this->assertNotNull($server);
    $server->delete();
    $this->assertSame('', $this->config('lws_authz.settings')->get('authorization_server'));

    $this->assertProblem($this->get('/lws/alice/root/'), 503, self::STORAGE . 'root/');
    $this->assertProblem($this->get('/lws/alice/root/', $this->token(self::ALICE)), 503, self::STORAGE . 'root/');
    // The description needs no token.
    $this->assertSame(200, $this->get('/lws/alice/')->getStatusCode());
  }

  /**
   * Tests that unknown storages answer 404 whatever the token.
   */
  public function testUnknownStorage(): void {
    $this->assertProblem($this->get('/lws/bob/root/'), 404, self::BASE . '/lws/bob/root/');
    $this->assertProblem($this->get('/lws/bob/root/', 'opaque-token'), 404, self::BASE . '/lws/bob/root/');
  }

  /**
   * Tests CORS headers, on success, on refusal and for preflight requests.
   */
  public function testCors(): void {
    $origin = ['Origin' => 'https://app.example'];
    $responses = [
      $this->send('GET', '/lws/alice/root/', $origin),
      $this->get('/lws/alice/root/', $this->token(self::ALICE)),
    ];
    foreach ($responses as $response) {
      $this->assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
      $exposed = array_map('trim', explode(',', (string) $response->headers->get('Access-Control-Expose-Headers')));
      foreach (['WWW-Authenticate', 'Link', 'ETag', 'Location'] as $header) {
        $this->assertContains($header, $exposed);
      }
      $this->assertFalse($response->headers->has('Access-Control-Allow-Credentials'));
    }

    $preflight = $this->send('OPTIONS', '/lws/alice/root/', $origin + [
      'Access-Control-Request-Method' => 'GET',
      'Access-Control-Request-Headers' => 'authorization',
    ]);
    $this->assertSame(204, $preflight->getStatusCode());
    $this->assertSame('*', $preflight->headers->get('Access-Control-Allow-Origin'));
    $this->assertSame('GET, HEAD, OPTIONS', $preflight->headers->get('Access-Control-Allow-Methods'));
    $this->assertContains('Authorization', array_map('trim', explode(',', (string) $preflight->headers->get('Access-Control-Allow-Headers'))));
    $this->assertSame('600', $preflight->headers->get('Access-Control-Max-Age'));
  }

}
