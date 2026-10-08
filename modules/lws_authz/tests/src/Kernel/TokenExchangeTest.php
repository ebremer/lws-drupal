<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_authz\Kernel;

use Drupal\lws\Outbound\HostResolverInterface;
use Drupal\lws_authz\Server\SigningKeys;
use Drupal\lws_authz\Token\JsonWebKeySet;
use Drupal\Tests\lws_storage\Kernel\LwsStorageKernelTestBase;
use Ebremer\Lws\Auth\ControlledIdentifierDocument;
use Ebremer\Lws\Auth\DidKey;
use Ebremer\Lws\Auth\Jwt;
use Ebremer\Lws\Auth\SelfSignedCredentials;
use Ebremer\Lws\Auth\SigningKey;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests this site's authorization server (LWS Core §5.2.2, §5.2.3).
 *
 * Its metadata and keys, token exchange with self-signed controlled
 * identifier credentials (lws10-authn-ssi-cid) for did:key, did:web and HTTPS
 * subjects, and the storage accepting the tokens it issues. The outbound
 * client answers from $this->documents.
 */
#[Group('lws')]
#[RunTestsInSeparateProcesses]
final class TokenExchangeTest extends LwsStorageKernelTestBase {

  /**
   * The storage, which trusts this site's server.
   */
  private const STORAGE = self::BASE . '/lws/alice/';

  /**
   * An agent with an HTTPS identifier.
   */
  private const WEBID = 'https://id.example/agent';

  private const JWT = 'urn:ietf:params:oauth:token-type:jwt';

  private const EXCHANGE = 'urn:ietf:params:oauth:grant-type:token-exchange';

  /**
   * The documents the outbound client serves, by URL: status and body.
   *
   * @var array<string, array{int, string}>
   */
  private array $documents = [];

  /**
   * The URLs fetched, in order.
   *
   * @var list<string>
   */
  private array $fetched = [];

  /**
   * The key of the did:key agent, a controller of the storage.
   */
  private SigningKey $agentKey;

  /**
   * The did:key agent.
   */
  private string $didKeyAgent;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->agentKey = SigningKey::generateP256();
    $this->didKeyAgent = DidKey::did($this->agentKey->publicKey);
    $this->config('lws_authz.settings')->set('authorization_server', 'local')->save();
    $this->storages->createStorage('alice', 'Alice', [$this->didKeyAgent, self::WEBID]);

    $handler = function (RequestInterface $request): FulfilledPromise {
      $url = (string) $request->getUri();
      $this->fetched[] = $url;
      [$status, $body] = $this->documents[$url] ?? [404, ''];
      return new FulfilledPromise(new GuzzleResponse($status, ['Content-Type' => 'application/json'], $body));
    };
    $this->container->set('http_client', new Client(['handler' => HandlerStack::create($handler)]));
    $this->container->set('lws.host_resolver', new class implements HostResolverInterface {

      /**
       * {@inheritdoc}
       */
      public function resolve(string $host): array {
        return ['93.184.215.14'];
      }

    });
  }

  /**
   * Sends a token request.
   *
   * @param array<string, string> $parameters
   *   The parameters.
   */
  private function tokenRequest(array $parameters): Response {
    return $this->send('POST', '/lws/oauth/token', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query($parameters));
  }

  /**
   * The parameters of a valid exchange of a credential.
   *
   * @return array<string, string>
   *   The parameters.
   */
  private function exchange(string $credential, string $resource = self::STORAGE): array {
    return [
      'grant_type' => self::EXCHANGE,
      'resource' => $resource,
      'subject_token' => $credential,
      'subject_token_type' => self::JWT,
    ];
  }

  /**
   * A credential of the did:key agent, signed with its key.
   *
   * @param array<string, mixed> $claims
   *   Claims to add or replace; NULL removes one.
   * @param array<string, mixed> $header
   *   Header parameters to add or replace; NULL removes one.
   * @param \Ebremer\Lws\Auth\SigningKey|null $key
   *   The key to sign with; the did:key agent's by default.
   * @param string|null $agent
   *   The subject; the did:key agent by default.
   */
  private function credential(array $claims = [], array $header = [], ?SigningKey $key = NULL, ?string $agent = NULL): string {
    $agent ??= $this->didKeyAgent;
    $now = time();
    $claims += [
      'sub' => $agent,
      'iss' => $agent,
      'client_id' => $agent,
      'aud' => [self::BASE],
      'iat' => $now,
      'exp' => $now + 300,
      'jti' => bin2hex(random_bytes(8)),
    ];
    $kid = str_starts_with($agent, 'did:key:') ? DidKey::keyIdForDid($agent) : $agent . '#key-1';
    $header += ['typ' => 'JWT', 'kid' => $kid];
    return Jwt::sign(array_filter($header, static fn ($v) => $v !== NULL), array_filter($claims, static fn ($v) => $v !== NULL), $key ?? $this->agentKey);
  }

  /**
   * Asserts an OAuth error response (RFC 6749 §5.2).
   */
  private function assertOauthError(Response $response, string $error, int $status = 400): void {
    $this->assertSame($status, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame('application/json', $response->headers->get('Content-Type'));
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    $body = $this->json($response);
    $this->assertSame($error, $body['error']);
    $this->assertIsString($body['error_description']);
  }

  /**
   * Asserts a successful token response, and returns the access token.
   */
  private function assertIssued(Response $response): string {
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame('application/json', $response->headers->get('Content-Type'));
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    $this->assertSame('no-cache', $response->headers->get('Pragma'));
    $body = $this->json($response);
    $this->assertSame('urn:ietf:params:oauth:token-type:access_token', $body['issued_token_type']);
    $this->assertSame('Bearer', $body['token_type']);
    $this->assertIsInt($body['expires_in']);
    $this->assertIsString($body['access_token']);
    return $body['access_token'];
  }

  /**
   * Tests the metadata, at both well-known paths.
   */
  public function testMetadata(): void {
    foreach (['/.well-known/lws-configuration', '/.well-known/oauth-authorization-server'] as $path) {
      $response = $this->send('GET', $path, ['Origin' => 'https://app.example']);
      $this->assertSame(200, $response->getStatusCode());
      $this->assertSame('application/json', $response->headers->get('Content-Type'));
      $this->assertSame('max-age=300, public', $response->headers->get('Cache-Control'));
      $this->assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
      $this->assertSame([
        'issuer' => self::BASE,
        'token_endpoint' => self::BASE . '/lws/oauth/token',
        'jwks_uri' => self::BASE . '/lws/oauth/jwks',
        'grant_types_supported' => [self::EXCHANGE],
        'response_types_supported' => ['token'],
        'token_endpoint_auth_methods_supported' => ['none'],
        'claims_supported' => ['sub', 'iss', 'client_id', 'aud', 'exp', 'iat', 'jti'],
        'subject_token_types_supported' => [self::JWT, 'urn:ietf:params:oauth:token-type:id_token'],
        'subject_identifier_types_supported' => ['https', 'did:key', 'did:web'],
      ], $this->json($response));
    }

    // Without suites, it accepts nothing, and says so.
    $this->config('lws_authz.settings')->set('suites', [])->save();
    $metadata = $this->json($this->send('GET', '/.well-known/lws-configuration'));
    $this->assertSame([], $metadata['subject_token_types_supported']);
    $this->assertSame([], $metadata['subject_identifier_types_supported']);
    $this->assertOauthError($this->tokenRequest($this->exchange($this->credential())), 'invalid_request');

    // The endpoints follow the LWS prefix.
    $this->config('lws.settings')->set('prefix', '/storage')->save();
    $this->container->get('router.builder')->rebuildIfNeeded();
    $this->assertSame(self::BASE . '/storage/oauth/token', $this->json($this->send('GET', '/.well-known/lws-configuration'))['token_endpoint']);
    $this->assertSame(200, $this->send('GET', '/storage/oauth/jwks')->getStatusCode());
  }

  /**
   * Tests exchanging a did:key credential, and using the token.
   */
  public function testExchange(): void {
    $jwks = $this->send('GET', '/lws/oauth/jwks');
    $this->assertSame(200, $jwks->getStatusCode());
    $this->assertSame('application/jwk-set+json', $jwks->headers->get('Content-Type'));
    $this->assertSame(['keys' => []], $this->json($jwks));

    // The PHP LWS client's credentials, as its token exchange sends them.
    $credentials = SelfSignedCredentials::didKey($this->agentKey);
    $before = time();
    $token = $this->assertIssued($this->tokenRequest($this->exchange($credentials->createToken(self::BASE))));
    $header = Jwt::decodeHeader($token);
    $claims = Jwt::decodeClaims($token);
    $this->assertSame('at+jwt', $header['typ']);
    $this->assertSame('ES256', $header['alg']);
    $this->assertSame(self::BASE, $claims['iss']);
    $this->assertSame($this->didKeyAgent, $claims['sub']);
    $this->assertSame($this->didKeyAgent, $claims['client_id']);
    $this->assertSame(self::STORAGE, $claims['aud']);
    $this->assertGreaterThanOrEqual($before, $claims['iat']);
    $this->assertSame(300, $claims['exp'] - $claims['iat']);
    $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $claims['jti']);

    // The first key was made, and is published with its thumbprint as kid.
    $keys = $this->json($this->send('GET', '/lws/oauth/jwks'))['keys'];
    $this->assertCount(1, $keys);
    $this->assertSame($header['kid'], $keys[0]['kid']);
    $this->assertSame(['kty', 'crv', 'x', 'y', 'kid', 'alg', 'use'], array_keys($keys[0]));
    $this->assertSame(SigningKeys::thumbprint(JsonWebKeySet::parse(['keys' => $keys])->candidates($header['kid'], 'ES256')[0]), $header['kid']);
    $this->assertSame(1, preg_match('/^[A-Za-z0-9_-]{43}$/', (string) $header['kid']));

    // The storage accepts it.
    $this->assertSame(200, $this->send('GET', '/lws/alice/root/', ['Authorization' => 'Bearer ' . $token])->getStatusCode());
    // Its challenge sends clients to this server.
    $challenge = (string) $this->send('GET', '/lws/alice/root/')->headers->get('WWW-Authenticate');
    $this->assertStringContainsString('as_uri="' . self::BASE . '"', $challenge);

    // The resource without its trailing slash is the audience as given.
    $token = $this->assertIssued($this->tokenRequest($this->exchange($this->credential(), rtrim(self::STORAGE, '/'))));
    $this->assertSame(rtrim(self::STORAGE, '/'), Jwt::decodeClaims($token)['aud']);
    $this->assertSame(200, $this->send('GET', '/lws/alice/root/', ['Authorization' => 'Bearer ' . $token])->getStatusCode());

    // An audience that is the issuer with a slash, and one of several.
    $this->assertIssued($this->tokenRequest($this->exchange($this->credential(['aud' => self::BASE . '/']))));
    $audiences = ['https://other.example', self::BASE];
    $this->assertIssued($this->tokenRequest($this->exchange($this->credential(['aud' => $audiences]))));
    // An Ed25519 did:key agent, not a controller: valid, but denied.
    $ed25519 = SigningKey::generateEd25519();
    $token = $this->assertIssued($this->tokenRequest($this->exchange($this->credential([], [], $ed25519, DidKey::did($ed25519->publicKey)))));
    $this->assertSame(403, $this->send('GET', '/lws/alice/root/', ['Authorization' => 'Bearer ' . $token])->getStatusCode());
  }

  /**
   * Tests that a token never outlives its credential, nor the lifetime set.
   */
  public function testLifetime(): void {
    $body = $this->json($this->tokenRequest($this->exchange($this->credential(['exp' => time() + 100]))));
    $this->assertLessThanOrEqual(100, $body['expires_in']);
    $this->assertGreaterThan(90, $body['expires_in']);

    $this->config('lws_authz.settings')->set('token_lifetime', 60)->save();
    $this->assertSame(60, $this->json($this->tokenRequest($this->exchange($this->credential())))['expires_in']);
  }

  /**
   * Tests malformed and unacceptable token requests.
   */
  public function testRequestErrors(): void {
    $valid = $this->exchange($this->credential());
    $form = ['Content-Type' => 'application/x-www-form-urlencoded'];

    $this->assertOauthError($this->send('POST', '/lws/oauth/token', ['Content-Type' => 'application/json'], json_encode($valid, JSON_THROW_ON_ERROR)), 'invalid_request');
    $this->assertOauthError($this->tokenRequest(['grant_type' => ''] + $valid), 'invalid_request');
    $this->assertOauthError($this->tokenRequest(['grant_type' => 'client_credentials'] + $valid), 'unsupported_grant_type');
    $this->assertOauthError($this->tokenRequest(array_diff_key($valid, ['resource' => 1])), 'invalid_request');
    $this->assertOauthError($this->tokenRequest(array_diff_key($valid, ['subject_token' => 1])), 'invalid_request');
    $this->assertOauthError($this->tokenRequest(array_diff_key($valid, ['subject_token_type' => 1])), 'invalid_request');
    $this->assertOauthError($this->tokenRequest(['subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token'] + $valid), 'invalid_request');
    $this->assertOauthError($this->tokenRequest(['requested_token_type' => 'urn:ietf:params:oauth:token-type:refresh_token'] + $valid), 'invalid_request');
    $this->assertOauthError($this->tokenRequest(['actor_token' => 'x'] + $valid), 'invalid_request');
    // A parameter twice.
    $this->assertOauthError($this->send('POST', '/lws/oauth/token', $form, http_build_query($valid) . '&resource=' . urlencode(self::STORAGE)), 'invalid_request');
    // Too large.
    $this->assertOauthError($this->send('POST', '/lws/oauth/token', $form, http_build_query($valid + ['padding' => str_repeat('a', 70000)])), 'invalid_request', 413);

    // Resources this server does not issue tokens for.
    $this->trustServer('other', 'https://other-as.example', SigningKey::generateP256(), 'other-key');
    $this->storages->createStorage('carol', 'Carol', [$this->didKeyAgent], NULL, 'other');
    foreach ([
      'https://unknown-storage.invalid/',
      self::BASE . '/lws/bob/',
      self::BASE . '/lws/carol/',
      self::BASE . '/lws/oauth/',
      self::STORAGE . 'root/',
      self::STORAGE . '/',
      'http://storage.example/lws/alice/',
    ] as $resource) {
      $this->assertOauthError($this->tokenRequest(['resource' => $resource] + $valid), 'invalid_target');
    }
    $this->assertOauthError($this->tokenRequest(['audience' => self::BASE . '/lws/carol/'] + $valid), 'invalid_target');
    $this->assertIssued($this->tokenRequest(['audience' => self::STORAGE] + $valid));

    // Last: in a kernel test, routing after a 405 refuses the next request
    // too, which a site, serving each request afresh, does not.
    $this->assertSame(405, $this->send('GET', '/lws/oauth/token')->getStatusCode());
  }

  /**
   * Tests credentials the self-signed suite refuses.
   *
   * @param array<string, mixed> $claims
   *   Claims to add or replace; NULL removes one.
   * @param array<string, mixed> $header
   *   Header parameters to add or replace; NULL removes one.
   * @param string $description
   *   A fragment of the error description.
   */
  #[DataProvider('faultProvider')]
  public function testCredentialFaults(array $claims, array $header, string $description): void {
    $response = $this->tokenRequest($this->exchange($this->credential($claims, $header)));
    $this->assertOauthError($response, 'invalid_request');
    $this->assertStringContainsString($description, $this->json($response)['error_description']);
  }

  /**
   * Data provider for testCredentialFaults().
   *
   * @return array<string, array{array<string, mixed>, array<string, mixed>, string}>
   *   Claims, header and a fragment of the description.
   */
  public static function faultProvider(): array {
    return [
      'expired' => [['exp' => time() - 120], [], 'expired'],
      'no exp' => [['exp' => NULL], [], '"exp"'],
      'exp not a number' => [['exp' => 'tomorrow'], [], '"exp"'],
      'no iat' => [['iat' => NULL], [], '"iat"'],
      'issued in the future' => [['iat' => time() + 600], [], 'future'],
      'not yet valid' => [['nbf' => time() + 600], [], 'not valid yet'],
      'no sub' => [['sub' => NULL], [], '"sub"'],
      'sub not a URI' => [['sub' => 'alice', 'iss' => 'alice', 'client_id' => 'alice'], [], '"sub"'],
      'no iss' => [['iss' => NULL], [], 'same URI'],
      'no client_id' => [['client_id' => NULL], [], 'same URI'],
      'client_id differs' => [['client_id' => 'https://app.example/id'], [], 'same URI'],
      'audience excludes this server' => [['aud' => ['https://other-as.example']], [], '"aud"'],
      'no audience' => [['aud' => NULL], [], '"aud"'],
      'no kid' => [[], ['kid' => NULL], '"kid"'],
      'kid names no method' => [[], ['kid' => 'did:key:zDnaeUnknown#zDnaeUnknown'], '"kid"'],
      'crit' => [[], ['crit' => ['exp']], 'critical'],
    ];
  }

  /**
   * Tests credentials whose signature is wrong or missing.
   */
  public function testSignatureFaults(): void {
    $credential = $this->credential();
    [$header, $claims, $signature] = explode('.', $credential);

    $bytes = (string) base64_decode(strtr($signature, '-_', '+/'));
    $bytes[10] = chr(ord($bytes[10]) ^ 1);
    $corrupted = $header . '.' . $claims . '.' . rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    $this->assertOauthError($this->tokenRequest($this->exchange($corrupted)), 'invalid_request');
    // Signed by another key than the one the did:key embeds.
    $this->assertOauthError($this->tokenRequest($this->exchange($this->credential([], [], SigningKey::generateP256()))), 'invalid_request');
    // Unsigned.
    $none = rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT"}'), '+/', '-_'), '=');
    $this->assertOauthError($this->tokenRequest($this->exchange($none . '.' . $claims . '.')), 'invalid_request');
    // HMAC, with the public key as the secret.
    $hs256 = rtrim(strtr(base64_encode('{"alg":"HS256","typ":"JWT","kid":"' . DidKey::keyIdForDid($this->didKeyAgent) . '"}'), '+/', '-_'), '=');
    $this->assertOauthError($this->tokenRequest($this->exchange($hs256 . '.' . $claims . '.' . $signature)), 'invalid_request');
    $this->assertOauthError($this->tokenRequest($this->exchange('not-a-jwt')), 'invalid_request');
    // A DID method this site does not resolve.
    $this->assertOauthError($this->tokenRequest($this->exchange($this->credential([], ['kid' => 'did:example:123#k'], NULL, 'did:example:123'))), 'invalid_request');
  }

  /**
   * Tests HTTPS subjects, whose documents are fetched.
   */
  public function testHttpsSubject(): void {
    $key = SigningKey::generateP256();
    $document = ControlledIdentifierDocument::create(self::WEBID, $key->publicKey, 'key-1');
    $this->documents[self::WEBID] = [200, json_encode($document, JSON_THROW_ON_ERROR)];
    $credential = fn (array $header = []) => $this->credential([], $header + ['kid' => 'key-1'], $key, self::WEBID);

    $token = $this->assertIssued($this->tokenRequest($this->exchange($credential())));
    $this->assertSame(self::WEBID, Jwt::decodeClaims($token)['sub']);
    $this->assertSame(200, $this->send('GET', '/lws/alice/root/', ['Authorization' => 'Bearer ' . $token])->getStatusCode());
    // The document's full method ID works as the kid too, and comes from the
    // cache.
    $this->assertIssued($this->tokenRequest($this->exchange($credential(['kid' => self::WEBID . '#key-1']))));
    $this->assertSame([self::WEBID], $this->fetched);
    // A kid that names nothing.
    $this->assertOauthError($this->tokenRequest($this->exchange($credential(['kid' => 'key-2']))), 'invalid_request');

    // A document that is not the subject's.
    $mismatched = json_encode(['id' => self::WEBID] + $document, JSON_THROW_ON_ERROR);
    $this->documents['https://id.example/other'] = [200, $mismatched];
    $other = $this->credential([], ['kid' => 'key-1'], $key, 'https://id.example/other');
    $response = $this->tokenRequest($this->exchange($other));
    $this->assertOauthError($response, 'invalid_request');
    $this->assertStringContainsString('"id" differs', $this->json($response)['error_description']);

    // A subject whose document cannot be had; the reason is not disclosed.
    $missing = $this->credential([], ['kid' => 'key-1'], $key, 'https://id.example/missing');
    $response = $this->tokenRequest($this->exchange($missing));
    $this->assertOauthError($response, 'invalid_request');
    $this->assertSame('The subject\'s controlled identifier document could not be retrieved.', $this->json($response)['error_description']);
    // The failure is remembered.
    $this->tokenRequest($this->exchange($missing));
    $this->assertCount(1, array_keys($this->fetched, 'https://id.example/missing', TRUE));

    // A subject the outbound guard refuses: plain HTTP.
    $plain = $this->credential([], ['kid' => 'key-1'], $key, 'http://id.example/agent');
    $this->assertOauthError($this->tokenRequest($this->exchange($plain)), 'invalid_request');
    $this->assertNotContains('http://id.example/agent', $this->fetched);
  }

  /**
   * Tests documents whose methods the subject may not authenticate with.
   */
  public function testHttpsSubjectMethods(): void {
    $key = SigningKey::generateP256();
    $method = [
      'id' => self::WEBID . '#key-1',
      'type' => 'JsonWebKey',
      'controller' => self::WEBID,
      'publicKeyJwk' => $key->publicKey->jwk() + ['kid' => 'key-1', 'alg' => 'ES256'],
    ];
    $cases = [
      'referenced' => [['verificationMethod' => [$method], 'authentication' => ['#key-1']], 200],
      'not for authentication' => [['verificationMethod' => [$method], 'assertionMethod' => ['#key-1']], 400],
      'revoked' => [['authentication' => [$method + ['revoked' => '2000-01-01T00:00:00Z']]], 400],
      'foreign controller' => [
        ['authentication' => [['controller' => 'https://id.example/someone-else'] + $method]],
        400,
      ],
    ];
    foreach ($cases as $name => [$members, $status]) {
      $subject = 'https://id.example/' . str_replace(' ', '-', $name);
      $document = json_encode(['@context' => ['https://www.w3.org/ns/cid/v1'], 'id' => $subject] + $members, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
      $this->documents[$subject] = [200, str_replace(self::WEBID, $subject, $document)];
      $response = $this->tokenRequest($this->exchange($this->credential([], ['kid' => 'key-1'], $key, $subject)));
      $this->assertSame($status, $response->getStatusCode(), $name . ': ' . $response->getContent());
    }
  }

  /**
   * Tests a did:web subject, whose document is fetched over HTTPS.
   */
  public function testDidWeb(): void {
    $key = SigningKey::generateEd25519();
    $did = 'did:web:id.example:alice';
    $document = ControlledIdentifierDocument::create($did, $key->publicKey, 'key-1');
    $this->documents['https://id.example/alice/did.json'] = [200, json_encode($document, JSON_THROW_ON_ERROR)];
    $token = $this->assertIssued($this->tokenRequest($this->exchange($this->credential([], ['kid' => $did . '#key-1'], $key, $did))));
    $this->assertSame($did, Jwt::decodeClaims($token)['sub']);
    $this->assertSame(['https://id.example/alice/did.json'], $this->fetched);
  }

  /**
   * Tests the rate limits per client address and per subject.
   */
  public function testRateLimits(): void {
    $this->config('lws_authz.settings')->set('rate_limits.client', 3)->set('rate_limits.subject', 1)->save();
    $this->assertIssued($this->tokenRequest($this->exchange($this->credential())));
    // The subject has had its token for this minute.
    $this->assertOauthError($this->tokenRequest($this->exchange($this->credential())), 'invalid_request', 429);
    $this->assertOauthError($this->tokenRequest(['grant_type' => 'password']), 'unsupported_grant_type');
    // The client has made its requests for this minute.
    $response = $this->tokenRequest($this->exchange($this->credential()));
    $this->assertOauthError($response, 'invalid_request', 429);
    $this->assertSame('60', $response->headers->get('Retry-After'));
  }

  /**
   * Tests key rotation: old tokens stay valid while they can be.
   */
  public function testRotation(): void {
    $keys = $this->container->get('lws_authz.signing_keys');
    $old = $this->assertIssued($this->tokenRequest($this->exchange($this->credential())));
    $oldKid = Jwt::decodeHeader($old)['kid'];

    $newKid = $keys->rotate();
    $new = $this->assertIssued($this->tokenRequest($this->exchange($this->credential())));
    $this->assertSame($newKid, Jwt::decodeHeader($new)['kid']);
    $this->assertNotSame($oldKid, $newKid);
    $this->assertSame([$newKid, $oldKid], array_column($this->json($this->send('GET', '/lws/oauth/jwks'))['keys'], 'kid'));
    foreach ([$old, $new] as $token) {
      $this->assertSame(200, $this->send('GET', '/lws/alice/root/', ['Authorization' => 'Bearer ' . $token])->getStatusCode());
    }

    // Once the newer key is older than a token's lifetime and the clock
    // skew, the old key is retired: no longer published, and deleted by the
    // next rotation.
    $directory = (string) $keys->directory();
    foreach ($keys->inventory() as $i => $entry) {
      rename($entry['path'], sprintf('%s/%010d-%s.jwk', $directory, time() - 1000 - $i, $entry['kid']));
    }
    $this->assertSame([TRUE, FALSE], array_column($keys->inventory(), 'published'));
    $this->assertSame([$newKid], array_column($this->json($this->send('GET', '/lws/oauth/jwks'))['keys'], 'kid'));
    $this->assertSame(401, $this->send('GET', '/lws/alice/root/', ['Authorization' => 'Bearer ' . $old])->getStatusCode());
    $latest = $keys->rotate();
    $this->assertSame([$latest, $newKid], array_column($keys->inventory(), 'kid'));
  }

  /**
   * Tests a site without a key directory: its server cannot issue tokens.
   *
   * Settings are read when the services are made, at the first request.
   */
  public function testUnavailable(): void {
    $this->setSetting('file_private_path', '');
    $this->assertOauthError($this->tokenRequest($this->exchange($this->credential())), 'temporarily_unavailable', 503);
    // Its storages cannot send clients anywhere.
    $this->assertSame(503, $this->send('GET', '/lws/alice/root/')->getStatusCode());
  }

  /**
   * Tests a key directory of its own, rather than the private file system.
   */
  public function testKeyDirectory(): void {
    $this->setSetting('lws_authz_key_directory', $this->siteDirectory . '/keys');
    $this->assertIssued($this->tokenRequest($this->exchange($this->credential())));
    // The site directory is a stream wrapper, which glob() cannot read.
    $files = preg_grep('/\.jwk$/', scandir($this->siteDirectory . '/keys') ?: []) ?: [];
    $this->assertCount(1, $files);
    $this->assertSame('0600', substr(sprintf('%o', fileperms($this->siteDirectory . '/keys/' . reset($files))), -4));
    $this->assertDirectoryDoesNotExist($this->siteDirectory . '/private/lws_authz/keys');
  }

}
