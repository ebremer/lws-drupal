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
 * Every 401 must carry a Bearer challenge whose "resource_metadata" is the
 * URL of the protected resource metadata of the URL requested (RFC 9728), and
 * a Link to the storage; a rejected token also an "error". By default the
 * challenge also keeps "as_uri" and "realm", for clients of earlier drafts. A
 * valid token without permission gets 403.
 */
#[Group('lws')]
#[RunTestsInSeparateProcesses]
final class StorageAuthorizationTest extends LwsStorageKernelTestBase {

  private const ALICE = 'https://id.example/alice';

  private const BOB = 'https://id.example/bob';

  private const STORAGE = self::BASE . '/lws/alice/';

  /**
   * The path of protected resource metadata, before the resource's path.
   */
  private const METADATA = '/.well-known/oauth-protected-resource';

  /**
   * The path and query of the last request sent.
   */
  private string $requested = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->storages->createStorage('alice', 'Alice', [self::ALICE]);
  }

  /**
   * {@inheritdoc}
   */
  protected function send(string $method, string $path, array $headers = [], ?string $body = NULL): Response {
    $this->requested = $path;
    return parent::send($method, $path, $headers, $body);
  }

  /**
   * Sends a GET with a bearer token, or none.
   */
  private function get(string $path, ?string $token = NULL): Response {
    return $this->send('GET', $path, $token === NULL ? [] : ['Authorization' => 'Bearer ' . $token]);
  }

  /**
   * Asserts a 401 with a conforming challenge, and returns the challenge.
   *
   * Its resource_metadata names the metadata of the last URL requested: the
   * well-known segment before its path.
   */
  private function assertChallenge(Response $response, ?string $error = NULL, string $storage = self::STORAGE, string $issuer = self::ISSUER): AuthChallenge {
    $this->assertSame(401, $response->getStatusCode());
    $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));
    $challenges = WwwAuthenticate::parse((string) $response->headers->get('WWW-Authenticate'));
    $this->assertCount(1, $challenges);
    $challenge = $challenges[0];
    $this->assertTrue($challenge->isScheme('Bearer'));
    $this->assertSame(self::BASE . self::METADATA . $this->requested, $challenge->resourceMetadata());
    // The parameters of earlier drafts, kept by default.
    $this->assertSame($issuer, $challenge->asUri());
    $this->assertSame($storage, $challenge->realm());
    $this->assertSame($error, $challenge->error());
    $this->assertContains('<' . $storage . '>; rel="https://www.w3.org/ns/lws#storage"', $response->headers->all('link'));
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
    // And an array that includes it among others.
    $audiences = ['https://another-storage.invalid/', self::STORAGE];
    $this->assertSame(200, $this->get('/lws/alice/root/', $this->token(self::ALICE, ['aud' => $audiences]))->getStatusCode());
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
      'others only' => [['aud' => ['https://other.invalid/', self::BASE . '/lws/bob/']], [], 'not for this storage'],
      'another storage' => [['aud' => self::BASE . '/lws/bob/'], [], 'not for this storage'],
      'a storage within the URI' => [['aud' => [self::STORAGE . 'root/']], [], 'not for this storage'],
      'no audience' => [['aud' => NULL], [], 'not for this storage'],
      'no audiences' => [['aud' => []], [], 'not for this storage'],
      'an audience that is not a string' => [['aud' => [self::STORAGE, 42]], [], '"aud"'],
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
   * Tests access tokens signed with RSA, as some authorization servers sign.
   */
  public function testRsaAuthorizationServer(): void {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    $this->assertNotFalse($key);
    $details = openssl_pkey_get_details($key);
    $this->assertIsArray($details);
    $b64 = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    $jwk = ['kty' => 'RSA', 'kid' => 'rsa-key', 'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e'])];
    $this->container->get('entity_type.manager')->getStorage('lws_trusted_as')->create([
      'id' => 'rsa',
      'label' => 'RSA',
      'issuer' => 'https://rsa-as.example',
      'jwks' => json_encode(['keys' => [$jwk]], JSON_THROW_ON_ERROR),
    ])->save();
    $this->storages->createStorage('carol', 'Carol', [self::ALICE], NULL, 'rsa');
    $realm = self::BASE . '/lws/carol/';
    $sign = static function (string $algorithm, array $claims) use ($b64, $key): string {
      $input = $b64((string) json_encode(['alg' => $algorithm, 'typ' => 'at+jwt', 'kid' => 'rsa-key'])) . '.' . $b64((string) json_encode($claims, JSON_UNESCAPED_SLASHES));
      openssl_sign($input, $signature, $key, $algorithm === 'RS512' ? OPENSSL_ALGO_SHA512 : OPENSSL_ALGO_SHA256);
      return $input . '.' . $b64((string) $signature);
    };
    $claims = [
      'iss' => 'https://rsa-as.example',
      'sub' => self::ALICE,
      'client_id' => 'https://app.example/id',
      'aud' => $realm,
      'iat' => time(),
      'exp' => time() + 300,
      'jti' => bin2hex(random_bytes(8)),
    ];
    foreach (['RS256', 'RS512'] as $algorithm) {
      $this->assertSame(200, $this->get('/lws/carol/root/', $sign($algorithm, $claims))->getStatusCode(), $algorithm);
    }
    // The signature is bound to its algorithm and its claims.
    $token = $sign('RS256', $claims);
    [$header, , $signature] = explode('.', $token);
    $other = $b64((string) json_encode(['sub' => self::BOB] + $claims));
    $this->assertChallenge($this->get('/lws/carol/root/', "$header.$other.$signature"), 'invalid_token', $realm, 'https://rsa-as.example');
    $relabelled = $b64((string) json_encode(['alg' => 'PS256', 'typ' => 'at+jwt', 'kid' => 'rsa-key']));
    $this->assertChallenge($this->get('/lws/carol/root/', "$relabelled." . explode('.', $token)[1] . ".$signature"), 'invalid_token', $realm, 'https://rsa-as.example');
  }

  /**
   * Tests a storage with no authorization server to send clients to.
   */
  public function testNoAuthorizationServer(): void {
    // Deleting the default server makes this site's own the default.
    $server = $this->container->get('entity_type.manager')->getStorage('lws_trusted_as')->load('test');
    $this->assertNotNull($server);
    $server->delete();
    $this->assertChallenge($this->get('/lws/alice/root/'), NULL, self::STORAGE, self::BASE);
    $this->assertChallenge($this->get('/lws/alice/root/', $this->token(self::ALICE)), 'invalid_token', self::STORAGE, self::BASE);

    $this->config('lws_authz.settings')->set('authorization_server', 'gone')->save();
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
    $this->assertSame('GET, HEAD, POST, OPTIONS', $preflight->headers->get('Access-Control-Allow-Methods'));
    $this->assertContains('Authorization', array_map('trim', explode(',', (string) $preflight->headers->get('Access-Control-Allow-Headers'))));
    $this->assertSame('600', $preflight->headers->get('Access-Control-Max-Age'));
  }

  /**
   * Tests that each challenge names the metadata of the URL requested.
   *
   * The URL as sent: its path still percent-encoded, as the client encoded
   * it, and its query unchanged (RFC 9728 §3.1, §3.3).
   */
  public function testResourceMetadata(): void {
    $this->agent = self::ALICE;
    $text = ['Content-Type' => 'text/plain', 'Slug' => 'notes.txt'];
    $this->assertSame(201, $this->send('POST', '/lws/alice/root/', $text, 'notes')->getStatusCode());
    $this->agent = NULL;

    $expected = [
      // A container, and a data resource.
      '/lws/alice/root/' => self::BASE . self::METADATA . '/lws/alice/root/',
      '/lws/alice/root/notes.txt' => self::BASE . self::METADATA . '/lws/alice/root/notes.txt',
      // A query, in the order sent.
      '/lws/alice/root/?z=1&a=%20b' => self::BASE . self::METADATA . '/lws/alice/root/?z=1&a=%20b',
      // Percent-encodings, in the case sent, and not normalised.
      '/lws/alice/root/caf%c3%a9%20menu.txt' => self::BASE . self::METADATA . '/lws/alice/root/caf%c3%a9%20menu.txt',
      '/lws/alice/root/%7Euser/' => self::BASE . self::METADATA . '/lws/alice/root/%7Euser/',
    ];
    foreach ($expected as $path => $metadata) {
      $this->assertSame($metadata, $this->assertChallenge($this->get($path))->resourceMetadata(), $path);
      $this->assertSame($metadata, $this->assertChallenge($this->get($path, 'not-a-token'), 'invalid_token')->resourceMetadata(), $path);
    }
    // HEAD, and writes.
    $this->assertChallenge($this->send('HEAD', '/lws/alice/root/notes.txt'));
    $this->assertChallenge($this->send('PUT', '/lws/alice/root/notes.txt', ['Content-Type' => 'text/plain'], 'changed'));
    $this->assertChallenge($this->send('POST', '/lws/alice/root/', ['Content-Type' => 'text/plain'], 'new'));
    $this->assertChallenge($this->send('DELETE', '/lws/alice/root/notes.txt'));
    $this->assertSame('notes', $this->body($this->get('/lws/alice/root/notes.txt', $this->token(self::ALICE))));
  }

  /**
   * Tests the challenge of a site whose base URL has a path.
   *
   * The metadata is at the well-known segment on the host, before the base
   * URL's path and the request's.
   */
  public function testResourceMetadataBelowRoot(): void {
    $base = self::BASE . '/sub';
    $this->config('lws.settings')->set('base_url', $base)->save();
    foreach (['/lws/alice/root/', '/lws/alice/root/a%20b?x=1'] as $path) {
      $response = $this->get($path);
      $this->assertSame(401, $response->getStatusCode());
      $challenge = WwwAuthenticate::parse((string) $response->headers->get('WWW-Authenticate'))[0];
      $this->assertSame(self::BASE . self::METADATA . '/sub' . $path, $challenge->resourceMetadata());
      $this->assertSame($base . '/lws/alice/', $challenge->realm());
      $this->assertContains('<' . $base . '/lws/alice/>; rel="https://www.w3.org/ns/lws#storage"', $response->headers->all('link'));
    }
  }

  /**
   * Tests the challenge parameters of the drafts before 19 October 2026.
   *
   * They follow resource_metadata by default, and go with
   * lws_authz.settings:legacy_challenge off.
   */
  public function testLegacyChallenge(): void {
    $metadata = self::BASE . self::METADATA . '/lws/alice/root/';
    $this->assertSame('Bearer resource_metadata="' . $metadata . '", as_uri="' . self::ISSUER . '", realm="' . self::STORAGE . '"', $this->get('/lws/alice/root/')->headers->get('WWW-Authenticate'));

    $this->config('lws_authz.settings')->set('legacy_challenge', FALSE)->save();
    $response = $this->get('/lws/alice/root/');
    $this->assertSame(401, $response->getStatusCode());
    $this->assertSame('Bearer resource_metadata="' . $metadata . '"', $response->headers->get('WWW-Authenticate'));
    $this->assertContains('<' . self::STORAGE . '>; rel="https://www.w3.org/ns/lws#storage"', $response->headers->all('link'));
    $challenge = WwwAuthenticate::parse((string) $this->get('/lws/alice/root/', 'not-a-token')->headers->get('WWW-Authenticate'))[0];
    $this->assertSame($metadata, $challenge->resourceMetadata());
    $this->assertSame('invalid_token', $challenge->error());
    $this->assertNotNull($challenge->errorDescription());
    $this->assertNull($challenge->asUri());
    $this->assertNull($challenge->realm());
  }

  /**
   * Tests that the update hook keeps the parameters on existing sites.
   */
  public function testLegacyChallengeUpdate(): void {
    $this->config('lws_authz.settings')->clear('legacy_challenge')->save();
    $this->container->get('module_handler')->loadInclude('lws_authz', 'install');
    $this->assertStringContainsString('as_uri', lws_authz_update_11005());
    $this->assertTrue($this->config('lws_authz.settings')->get('legacy_challenge'));
    // Run again, it changes nothing.
    $this->config('lws_authz.settings')->set('legacy_challenge', FALSE)->save();
    $this->assertStringContainsString('already', lws_authz_update_11005());
    $this->assertFalse($this->config('lws_authz.settings')->get('legacy_challenge'));
  }

}
