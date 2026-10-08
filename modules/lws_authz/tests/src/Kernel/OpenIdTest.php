<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_authz\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\lws\Outbound\HostResolverInterface;
use Drupal\lws_authz\Entity\TrustedIssuerInterface;
use Drupal\Tests\lws_storage\Kernel\LwsStorageKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Ebremer\Lws\Auth\Jwt;
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
 * Tests exchanging ID Tokens of OpenID Providers (lws10-authn-openid).
 *
 * The provider https://op.example signs with ES256, and https://rsa.example
 * with RS256, as Keycloak does. Their discovery documents, keys and the
 * subjects' controlled identifier documents come from $this->documents.
 */
#[Group('lws')]
#[RunTestsInSeparateProcesses]
final class OpenIdTest extends LwsStorageKernelTestBase {

  use UserCreationTrait;

  private const STORAGE = self::BASE . '/lws/alice/';

  private const OP = 'https://op.example';

  private const RSA_OP = 'https://rsa.example/realms/main';

  private const WEBID = 'https://id.example/alice';

  private const CLIENT = 'https://app.example/id';

  private const ID_TOKEN = 'urn:ietf:params:oauth:token-type:id_token';

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
   * The ES256 key of https://op.example.
   */
  private SigningKey $opKey;

  /**
   * The RSA key of https://rsa.example.
   */
  private \OpenSSLAsymmetricKey $rsaKey;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config('lws_authz.settings')->set('authorization_server', 'local')->save();
    $this->storages->createStorage('alice', 'Alice', [self::WEBID]);

    $this->opKey = SigningKey::generateP256();
    $this->provider(self::OP, ['keys' => [$this->opKey->publicKey->jwk() + ['kid' => 'op-1', 'alg' => 'ES256']]]);
    $rsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    $this->assertNotFalse($rsa);
    $this->rsaKey = $rsa;
    $details = openssl_pkey_get_details($rsa);
    $this->assertIsArray($details);
    $public = ['kty' => 'RSA', 'n' => self::b64($details['rsa']['n']), 'e' => self::b64($details['rsa']['e'])];
    $this->provider(self::RSA_OP, [
      'keys' => [
        // Keycloak publishes an encryption key beside the signing one.
        $public + ['kid' => 'enc-1', 'use' => 'enc', 'alg' => 'RSA-OAEP'],
        $public + ['kid' => 'rsa-1', 'use' => 'sig', 'alg' => 'RS256'],
      ],
    ]);
    $this->subject(self::WEBID, [self::OP, self::RSA_OP]);

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
   * How many fetches were made.
   */
  private function fetches(): int {
    return count($this->fetched);
  }

  /**
   * Base64url.
   */
  private static function b64(string $bytes): string {
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
  }

  /**
   * Serves a provider's discovery document and keys.
   *
   * @param string $issuer
   *   The issuer.
   * @param array<string, mixed> $jwks
   *   Its key set.
   * @param string|null $claimed
   *   The issuer its discovery document names, if not itself.
   */
  private function provider(string $issuer, array $jwks, ?string $claimed = NULL): void {
    $discovery = [
      'issuer' => $claimed ?? $issuer,
      'jwks_uri' => $issuer . '/certs',
      'response_types_supported' => ['id_token'],
    ];
    $this->documents[$issuer . '/.well-known/openid-configuration'] = [200, (string) json_encode($discovery)];
    $this->documents[$issuer . '/certs'] = [200, (string) json_encode($jwks)];
  }

  /**
   * Serves a subject's controlled identifier document.
   *
   * @param string $subject
   *   The subject.
   * @param list<string> $providers
   *   The issuers it names as its OpenID Providers.
   */
  private function subject(string $subject, array $providers): void {
    $document = [
      '@context' => ['https://www.w3.org/ns/cid/v1'],
      'id' => $subject,
      'service' => array_map(static fn (string $issuer): array => [
        'type' => 'https://www.w3.org/ns/lws#OpenIdProvider',
        'serviceEndpoint' => $issuer,
      ], $providers),
    ];
    $this->documents[$subject] = [200, (string) json_encode($document)];
  }

  /**
   * An ID Token of https://op.example.
   *
   * @param array<string, mixed> $claims
   *   Claims to add or replace; NULL removes one.
   * @param array<string, mixed> $header
   *   Header parameters to add or replace; NULL removes one.
   * @param \Ebremer\Lws\Auth\SigningKey|null $key
   *   The key to sign with; the provider's by default.
   */
  private function idToken(array $claims = [], array $header = [], ?SigningKey $key = NULL): string {
    $now = time();
    $claims += [
      'iss' => self::OP,
      'sub' => self::WEBID,
      'azp' => self::CLIENT,
      'aud' => [self::CLIENT, self::BASE],
      'iat' => $now,
      'exp' => $now + 300,
    ];
    $header += ['typ' => 'JWT', 'kid' => 'op-1'];
    $header = array_filter($header, static fn ($v) => $v !== NULL);
    $token = Jwt::sign($header, array_filter($claims, static fn ($v) => $v !== NULL), $key ?? $this->opKey);
    // Jwt::sign() sets "alg" from the key; another is written in afterwards.
    if (isset($header['alg'])) {
      $parts = explode('.', $token);
      $parts[0] = self::b64((string) json_encode($header));
      $token = implode('.', $parts);
    }
    return $token;
  }

  /**
   * An RS256 ID Token of https://rsa.example.
   *
   * @param array<string, mixed> $claims
   *   Claims to add or replace.
   */
  private function rsaIdToken(array $claims = []): string {
    $now = time();
    $claims += [
      'iss' => self::RSA_OP,
      'sub' => self::WEBID,
      'azp' => self::CLIENT,
      'aud' => self::CLIENT,
      'iat' => $now,
      'exp' => $now + 300,
    ];
    $input = self::b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'rsa-1'])) . '.' . self::b64((string) json_encode($claims, JSON_UNESCAPED_SLASHES));
    $this->assertTrue(openssl_sign($input, $signature, $this->rsaKey, OPENSSL_ALGO_SHA256));
    return $input . '.' . self::b64($signature);
  }

  /**
   * Exchanges an ID Token at this site's token endpoint.
   */
  private function exchange(string $idToken, string $type = self::ID_TOKEN): Response {
    return $this->send('POST', '/lws/oauth/token', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
      'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
      'resource' => self::STORAGE,
      'subject_token' => $idToken,
      'subject_token_type' => $type,
    ]));
  }

  /**
   * Asserts that an exchange was refused, and returns why.
   */
  private function assertRefused(Response $response): string {
    $this->assertSame(400, $response->getStatusCode(), (string) $response->getContent());
    $body = $this->json($response);
    $this->assertSame('invalid_request', $body['error']);
    return (string) $body['error_description'];
  }

  /**
   * Asserts that an exchange succeeded, and returns the access token's claims.
   *
   * The storage accepts the token: alice, its controller, may read it, and
   * anyone else is known but refused.
   *
   * @return array<array-key, mixed>
   *   The claims.
   */
  private function assertIssued(Response $response): array {
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $token = $this->json($response)['access_token'];
    $this->assertIsString($token);
    $claims = Jwt::decodeClaims($token);
    $read = $this->send('GET', '/lws/alice/root/', ['Authorization' => 'Bearer ' . $token]);
    $this->assertSame($claims['sub'] === self::WEBID ? 200 : 403, $read->getStatusCode());
    return $claims;
  }

  /**
   * Configures a trusted provider.
   *
   * @param array<string, mixed> $values
   *   Values to add or replace.
   */
  private function trust(array $values = []): TrustedIssuerInterface {
    $provider = $this->container->get('entity_type.manager')->getStorage('lws_trusted_issuer')->create($values + [
      'id' => 'main',
      'label' => 'Main',
      'issuer' => self::RSA_OP,
      'require_as_audience' => TRUE,
      'verify_subject' => TRUE,
      'status' => TRUE,
    ]);
    assert($provider instanceof TrustedIssuerInterface);
    $provider->save();
    return $provider;
  }

  /**
   * Tests the trust path: discovery, signature, then the subject's document.
   */
  public function testDiscoveredTrust(): void {
    $metadata = $this->json($this->send('GET', '/.well-known/lws-configuration'));
    $this->assertContains(self::ID_TOKEN, $metadata['subject_token_types_supported']);
    $this->assertContains('https', $metadata['subject_identifier_types_supported']);

    $claims = $this->assertIssued($this->exchange($this->idToken()));
    $this->assertSame(self::WEBID, $claims['sub']);
    $this->assertSame(self::CLIENT, $claims['client_id']);
    $this->assertSame(self::BASE, $claims['iss']);
    $this->assertSame([
      self::OP . '/.well-known/openid-configuration',
      self::OP . '/certs',
      self::WEBID,
    ], $this->fetched);
    // Keys and documents are cached.
    $this->assertIssued($this->exchange($this->idToken(['aud' => self::BASE . '/'])));
    $this->assertSame(3, $this->fetches());
  }

  /**
   * Tests a provider that signs with RSA, and publishes an encryption key.
   */
  public function testRsa(): void {
    // Its ID Tokens name only their client: a configured provider may waive
    // this server as the audience, as Keycloak's tokens need.
    $this->assertStringContainsString('"aud" must include', $this->assertRefused($this->exchange($this->rsaIdToken())));
    $this->trust(['require_as_audience' => FALSE]);
    $claims = $this->assertIssued($this->exchange($this->rsaIdToken()));
    $this->assertSame(self::WEBID, $claims['sub']);
    // The client must still be among the audiences.
    $this->assertStringContainsString('azp', $this->assertRefused($this->exchange($this->rsaIdToken(['aud' => 'https://other.example/id']))));
  }

  /**
   * Tests ID Tokens that are refused, and why.
   *
   * @param array<string, mixed> $claims
   *   Claims to add or replace; NULL removes one.
   * @param array<string, mixed> $header
   *   Header parameters to add or replace; NULL removes one.
   * @param string $reason
   *   A fragment of the error description.
   */
  #[DataProvider('faultProvider')]
  public function testFaults(array $claims, array $header, string $reason): void {
    $this->assertStringContainsString($reason, $this->assertRefused($this->exchange($this->idToken($claims, $header))));
  }

  /**
   * Data provider for testFaults().
   *
   * @return array<string, array{array<string, mixed>, array<string, mixed>, string}>
   *   Claims, header and a fragment of the reason.
   */
  public static function faultProvider(): array {
    return [
      'an HMAC' => [[], ['alg' => 'HS256'], 'must be signed with'],
      'an access token' => [[], ['typ' => 'at+jwt'], 'access token'],
      'critical parameters' => [[], ['crit' => ['exp']], 'critical'],
      'no subject' => [['sub' => NULL], [], '"sub"'],
      'a subject that is no URI' => [['sub' => 'alice'], [], '"sub"'],
      'no client' => [['azp' => NULL], [], '"azp"'],
      'a client that is no URI' => [['azp' => 'app'], [], '"azp"'],
      'an issuer that is no URL' => [['iss' => 'op'], [], '"iss"'],
      'no audience' => [['aud' => NULL], [], '"aud"'],
      'another audience' => [['aud' => [self::CLIENT]], [], '"aud" must include'],
      'expired' => [['iat' => time() - 900, 'exp' => time() - 600], [], 'expired'],
      'no expiry' => [['exp' => NULL], [], '"exp"'],
      'no issue time' => [['iat' => NULL], [], '"iat"'],
      'issued in the future' => [['iat' => time() + 600], [], 'future'],
      'not valid yet' => [['nbf' => time() + 600], [], 'not valid yet'],
      'an unknown key' => [[], ['kid' => 'op-9'], 'does not verify'],
    ];
  }

  /**
   * Tests signatures and keys that do not verify, without the subject fetched.
   */
  public function testSignatures(): void {
    $this->assertStringContainsString('does not verify', $this->assertRefused($this->exchange($this->idToken([], [], SigningKey::generateP256()))));
    $unsigned = implode('.', array_slice(explode('.', $this->idToken([], ['alg' => 'none'])), 0, 2)) . '.';
    $this->assertRefused($this->exchange($unsigned));
    $this->assertNotContains(self::WEBID, $this->fetched);

    // A key ID the cached keys lack fetches them again, once a minute.
    $this->assertRefused($this->exchange($this->idToken([], ['kid' => 'op-2'])));
    $this->assertRefused($this->exchange($this->idToken([], ['kid' => 'op-3'])));
    $this->assertCount(2, array_keys($this->fetched, self::OP . '/certs', TRUE));

    // A discovery document for another issuer.
    $this->provider('https://mallory.example', ['keys' => [$this->opKey->publicKey->jwk() + ['kid' => 'op-1']]], self::OP);
    $this->assertStringContainsString('cannot be obtained', $this->assertRefused($this->exchange($this->idToken(['iss' => 'https://mallory.example']))));
  }

  /**
   * Tests subjects whose documents do not name the provider.
   */
  public function testSubjects(): void {
    // A provider the subject never named.
    $this->provider('https://rogue.example', ['keys' => [$this->opKey->publicKey->jwk() + ['kid' => 'op-1']]]);
    $this->assertStringContainsString('does not name https://rogue.example', $this->assertRefused($this->exchange($this->idToken(['iss' => 'https://rogue.example']))));
    // A document that is not the subject's.
    $this->documents['https://id.example/bob'] = $this->documents[self::WEBID];
    $this->assertStringContainsString('"id" differs', $this->assertRefused($this->exchange($this->idToken(['sub' => 'https://id.example/bob']))));
    // A subject with no document.
    $this->assertRefused($this->exchange($this->idToken(['sub' => 'https://id.example/nobody'])));

    // Discovered trust can be turned off; then only configured providers are.
    $this->config('lws_authz.settings')->set('openid.discovery', FALSE)->save();
    $this->assertStringContainsString('does not trust', $this->assertRefused($this->exchange($this->idToken())));
    $this->trust(['issuer' => self::OP]);
    $this->assertIssued($this->exchange($this->idToken()));
  }

  /**
   * Tests a provider trusted for any subject, with pinned keys.
   */
  public function testConfiguredProvider(): void {
    $provider = $this->trust([
      'issuer' => self::OP,
      'verify_subject' => FALSE,
      'jwks' => json_encode(['keys' => [$this->opKey->publicKey->jwk() + ['kid' => 'op-1']]]),
    ]);
    // A subject with no document, and nothing fetched.
    $claims = $this->assertIssued($this->exchange($this->idToken(['sub' => 'https://id.example/nobody'])));
    $this->assertSame('https://id.example/nobody', $claims['sub']);
    $this->assertSame([], $this->fetched);

    // Disabled, it is not trusted, and discovery decides.
    $provider->setStatus(FALSE)->save();
    $this->assertRefused($this->exchange($this->idToken(['sub' => 'https://id.example/nobody'])));
    $this->assertIssued($this->exchange($this->idToken()));
  }

  /**
   * Tests the form that configures a provider.
   */
  public function testProviderForm(): void {
    $this->setUpCurrentUser([], ['administer lws']);
    $submit = function (array $values): array {
      $form = $this->container->get('entity_type.manager')->getFormObject('lws_trusted_issuer', 'add');
      $form->setEntity($this->container->get('entity_type.manager')->getStorage('lws_trusted_issuer')->create());
      $state = (new FormState())->setValues($values + [
        'label' => 'Keycloak',
        'id' => 'keycloak',
        'issuer' => self::RSA_OP,
        'jwks' => '',
        // A programmatic submission leaves a checkbox unchecked with NULL.
        'require_as_audience' => NULL,
        'verify_subject' => 1,
        'status' => 1,
        'op' => 'Save',
      ]);
      $this->container->get('form_builder')->submitForm($form, $state);
      return array_map('strval', $state->getErrors());
    };
    $this->assertSame([], $submit([]));
    $provider = $this->container->get('entity_type.manager')->getStorage('lws_trusted_issuer')->load('keycloak');
    $this->assertInstanceOf(TrustedIssuerInterface::class, $provider);
    $this->assertSame(self::RSA_OP, $provider->getIssuer());
    $this->assertFalse($provider->requiresAsAudience());
    $this->assertTrue($provider->verifiesSubject());
    $this->assertNull($provider->getJwks());
    // One provider per issuer; pinned keys must be keys; issuers are URLs.
    $this->assertArrayHasKey('issuer', $submit(['id' => 'again']));
    $secret = '{"keys":[{"kty":"oct","k":"c2VjcmV0"}]}';
    $this->assertArrayHasKey('jwks', $submit(['id' => 'other', 'issuer' => self::OP, 'jwks' => $secret]));
    $this->assertArrayHasKey('issuer', $submit(['id' => 'ftp', 'issuer' => 'ftp://op.example']));
    // Pinned keys keep only their public members.
    $private = json_encode(['keys' => [$this->opKey->jwk() + ['kid' => 'op-1']]]);
    $this->assertSame([], $submit(['id' => 'pinned', 'issuer' => self::OP, 'jwks' => $private]));
    $pinned = $this->container->get('entity_type.manager')->getStorage('lws_trusted_issuer')->load('pinned');
    $this->assertInstanceOf(TrustedIssuerInterface::class, $pinned);
    $this->assertStringNotContainsString('"d"', (string) $pinned->getJwks());
  }

  /**
   * Tests that the suite can be turned off.
   */
  public function testSuiteDisabled(): void {
    $this->config('lws_authz.settings')->set('suites', ['ssi_cid'])->save();
    $this->assertNotContains(self::ID_TOKEN, $this->json($this->send('GET', '/.well-known/lws-configuration'))['subject_token_types_supported']);
    $this->assertRefused($this->exchange($this->idToken()));
    $this->assertSame([], $this->fetched);
  }

}
