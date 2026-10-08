<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_storage\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\ResourceRepository;
use Drupal\lws_storage\StorageManager;
use Ebremer\Lws\Auth\Jwt;
use Ebremer\Lws\Auth\SigningKey;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base class for kernel tests of the storage module.
 *
 * Storages trust a test authorization server whose keys are pinned, and whose
 * private key the tests hold, so they can mint any token they need.
 */
abstract class LwsStorageKernelTestBase extends KernelTestBase {

  protected const BASE = 'https://storage.example';

  /**
   * The issuer of the test authorization server.
   */
  protected const ISSUER = 'https://as.example';

  /**
   * The key ID of its signing key.
   */
  protected const KID = 'test-key';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'lws', 'lws_authz', 'lws_storage'];

  /**
   * The signing key of the test authorization server.
   */
  protected SigningKey $signingKey;

  /**
   * The agent whose token send() presents when the request has none.
   *
   * NULL sends requests without a token.
   */
  protected ?string $agent = NULL;

  /**
   * The storage manager.
   */
  protected StorageManager $storages;

  /**
   * The resource repository.
   */
  protected ResourceRepository $resources;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('lws_storage');
    $this->installEntitySchema('lws_resource');
    $this->installEntitySchema('date_format');
    $this->installConfig(['system', 'lws', 'lws_authz']);
    $this->config('lws.settings')->set('base_url', self::BASE)->save();
    $this->storages = $this->container->get('lws_storage.storage_manager');
    $this->resources = $this->container->get('lws_storage.resource_repository');

    $this->signingKey = SigningKey::generateP256();
    $this->trustServer('test', self::ISSUER, $this->signingKey, self::KID);
    $this->config('lws_authz.settings')->set('authorization_server', 'test')->save();
  }

  /**
   * Trusts an authorization server with a pinned key.
   */
  protected function trustServer(string $id, string $issuer, SigningKey $key, string $kid): void {
    $jwk = $key->publicKey->jwk() + ['kid' => $kid];
    $this->container->get('entity_type.manager')->getStorage('lws_trusted_as')->create([
      'id' => $id,
      'label' => $id,
      'issuer' => $issuer,
      'jwks' => json_encode(['keys' => [$jwk]], JSON_THROW_ON_ERROR),
    ])->save();
  }

  /**
   * Mints an access token from the test authorization server.
   *
   * @param string $subject
   *   The agent URI.
   * @param array<string, mixed> $claims
   *   Claims to add or replace; NULL removes one.
   * @param array<string, mixed> $header
   *   Header parameters to add or replace; NULL removes one.
   * @param \Ebremer\Lws\Auth\SigningKey|null $key
   *   The key to sign with; the test server's by default.
   */
  protected function token(string $subject, array $claims = [], array $header = [], ?SigningKey $key = NULL): string {
    $now = time();
    $claims += [
      'iss' => self::ISSUER,
      'sub' => $subject,
      'client_id' => 'https://app.example/id',
      'aud' => self::BASE . '/lws/alice/',
      'iat' => $now,
      'exp' => $now + 300,
      'jti' => bin2hex(random_bytes(8)),
    ];
    $header += ['typ' => 'at+jwt', 'kid' => self::KID];
    return Jwt::sign(array_filter($header, static fn ($v) => $v !== NULL), array_filter($claims, static fn ($v) => $v !== NULL), $key ?? $this->signingKey);
  }

  /**
   * Loads a storage by slug.
   */
  protected function loadStorage(string $slug): LwsStorageInterface {
    $storages = $this->container->get('entity_type.manager')->getStorage('lws_storage')->loadByProperties(['slug' => $slug]);
    $storage = reset($storages);
    $this->assertInstanceOf(LwsStorageInterface::class, $storage);
    return $storage;
  }

  /**
   * Sends a request through the HTTP kernel.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $path
   *   The raw path, with any query string.
   * @param array<string, string> $headers
   *   Request headers.
   */
  protected function send(string $method, string $path, array $headers = []): Response {
    $request = Request::create(self::BASE . $path, $method);
    if ($this->agent !== NULL && !isset($headers['Authorization'])) {
      $headers['Authorization'] = 'Bearer ' . $this->token($this->agent);
    }
    foreach ($headers as $name => $value) {
      $request->headers->set($name, $value);
    }
    return $this->container->get('http_kernel')->handle($request);
  }

  /**
   * Decodes a JSON response body.
   *
   * @return array<string, mixed>
   *   The document.
   */
  protected function json(Response $response): array {
    $body = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertIsArray($body);
    return $body;
  }

  /**
   * Asserts that a response is a problem details document.
   */
  protected function assertProblem(Response $response, int $status, ?string $instance = NULL): void {
    $this->assertSame($status, $response->getStatusCode());
    $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));
    $problem = $this->json($response);
    $this->assertSame($status, $problem['status']);
    $this->assertSame($instance, $problem['instance'] ?? NULL);
  }

}
