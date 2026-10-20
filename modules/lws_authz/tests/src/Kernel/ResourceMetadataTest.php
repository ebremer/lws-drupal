<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_authz\Kernel;

use Drupal\Tests\lws_storage\Kernel\LwsStorageKernelTestBase;
use Ebremer\Lws\Auth\ProtectedResourceMetadata;
use Ebremer\Lws\Auth\SigningKey;
use Ebremer\Lws\Http\Link;
use Ebremer\Lws\Http\LinkHeader;
use Ebremer\Lws\Http\WwwAuthenticate;
use Ebremer\Lws\LinkRelation;
use Ebremer\Lws\Model\StorageDescription;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the protected resource metadata of resources (RFC 9728).
 *
 * LWS Core, Protected Resource Metadata: every 401 names the metadata of the
 * URL requested, which names that URL as its resource and the storage's
 * authorization server, the same for every URL of a storage.
 */
#[Group('lws')]
#[RunTestsInSeparateProcesses]
final class ResourceMetadataTest extends LwsStorageKernelTestBase {

  private const ALICE = 'https://id.example/alice';

  private const STORAGE = self::BASE . '/lws/alice/';

  /**
   * The path of protected resource metadata, before the resource's path.
   */
  private const METADATA = '/.well-known/oauth-protected-resource';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->storages->createStorage('alice', 'Alice', [self::ALICE]);
  }

  /**
   * Fetches the metadata of a resource path, as Drupal receives it.
   *
   * @param string $path
   *   The resource's path, with any query string.
   * @param array<string, string|list<string>> $headers
   *   Request headers.
   * @param string $method
   *   The method.
   */
  private function metadata(string $path, array $headers = [], string $method = 'GET'): Response {
    return $this->send($method, self::METADATA . $path, $headers);
  }

  /**
   * Asserts a metadata document, and returns it.
   *
   * @return array<string, mixed>
   *   The document.
   */
  private function assertMetadata(Response $response, string $resource, string $issuer = self::ISSUER): array {
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame('application/json', $response->headers->get('Content-Type'));
    $document = $this->json($response);
    $this->assertSame([
      'resource' => $resource,
      'authorization_servers' => [$issuer],
      'bearer_methods_supported' => ['header'],
    ], $document);
    return $document;
  }

  /**
   * Tests the document of a container.
   */
  public function testDocument(): void {
    $response = $this->metadata('/lws/alice/root/');
    $this->assertMetadata($response, self::STORAGE . 'root/');
    // Cached as the authorization server's metadata is, and never by Drupal.
    $this->assertSame('max-age=300, public', $response->headers->get('Cache-Control'));
    $route = $this->container->get('router.route_provider')->getRouteByName('lws_authz.resource_metadata');
    $this->assertTrue($route->getOption('no_cache'));
    // The client library reads it.
    $parsed = ProtectedResourceMetadata::parse($this->json($response));
    $this->assertTrue($parsed->describes(self::STORAGE . 'root/'));
    $this->assertSame([self::ISSUER], $parsed->authorizationServers);

    $head = $this->metadata('/lws/alice/root/', [], 'HEAD');
    $this->assertSame(200, $head->getStatusCode());
    $this->assertSame('application/json', $head->headers->get('Content-Type'));
    $this->assertSame('', (string) $head->getContent());
  }

  /**
   * Tests that the resource is the URL requested, character for character.
   */
  public function testResourceAsRequested(): void {
    $paths = [
      // The storage itself, and a data resource.
      '/lws/alice/',
      '/lws/alice/root/notes.txt',
      // Percent-encodings as sent: not decoded, normalised or re-cased.
      '/lws/alice/root/caf%C3%A9%20menu.txt',
      '/lws/alice/root/caf%c3%a9/',
      '/lws/alice/root/%7Euser/',
      // The query as sent, not sorted or re-encoded.
      '/lws/alice/root/?z=1&a=%20b',
      '/lws/alice/root/?page=x',
      // The other areas of the storage.
      '/lws/alice/access/grants/',
      '/lws/alice/notifications/',
    ];
    foreach ($paths as $path) {
      $this->assertMetadata($this->metadata($path), self::BASE . $path);
    }
  }

  /**
   * Tests a site whose base URL has a path, behind a path-stripping proxy.
   *
   * Clients ask for the metadata at the well-known segment followed by the
   * base path and the resource's path; the proxy sends Drupal the well-known
   * segment followed by the resource's path as Drupal receives it.
   */
  public function testBasePath(): void {
    $base = self::BASE . '/targets/drupal';
    $this->config('lws.settings')->set('base_url', $base . '/')->save();
    $this->assertMetadata($this->metadata('/lws/alice/root/caf%C3%A9.txt?x=1'), $base . '/lws/alice/root/caf%C3%A9.txt?x=1');

    $response = $this->send('GET', '/lws/alice/root/caf%C3%A9.txt?x=1');
    $challenge = WwwAuthenticate::parse((string) $response->headers->get('WWW-Authenticate'))[0];
    $this->assertSame(self::BASE . self::METADATA . '/targets/drupal/lws/alice/root/caf%C3%A9.txt?x=1', $challenge->resourceMetadata());
    $this->assertContains('<' . $base . '/lws/alice/>; rel="https://www.w3.org/ns/lws#storage"', $response->headers->all('link'));
  }

  /**
   * Tests the authorization server of each storage.
   */
  public function testAuthorizationServers(): void {
    // Alice's storage trusts the site's default, here a trusted server.
    $this->assertMetadata($this->metadata('/lws/alice/root/'), self::STORAGE . 'root/');
    // Carol's names its own.
    $this->trustServer('other', 'https://other-as.example', SigningKey::generateP256(), 'other-key');
    $this->storages->createStorage('carol', 'Carol', [self::ALICE], NULL, 'other');
    $this->assertMetadata($this->metadata('/lws/carol/root/'), self::BASE . '/lws/carol/root/', 'https://other-as.example');
    // This site's own server names its issuer, the base URL.
    $this->config('lws_authz.settings')->set('authorization_server', 'local')->save();
    $this->assertMetadata($this->metadata('/lws/alice/root/'), self::STORAGE . 'root/', self::BASE);
    $this->assertMetadata($this->metadata('/lws/carol/root/'), self::BASE . '/lws/carol/root/', 'https://other-as.example');
  }

  /**
   * Tests that it reveals nothing about what is stored, and needs no token.
   */
  public function testSameForEveryResource(): void {
    $this->agent = self::ALICE;
    $text = ['Content-Type' => 'text/plain', 'Slug' => 'private.txt'];
    $this->assertSame(201, $this->send('POST', '/lws/alice/root/', $text, 'secret')->getStatusCode());
    $this->agent = NULL;
    $this->assertSame(401, $this->send('GET', '/lws/alice/root/private.txt')->getStatusCode());

    // Whatever the request carries: the token is not looked at.
    $authorizations = [
      'Bearer not-a-token',
      'Bearer ' . $this->token(self::ALICE, ['exp' => time() - 3600]),
      'Basic YWxpY2U6c2VjcmV0',
      ['Bearer a', 'Bearer b'],
    ];
    $documents = [];
    foreach (['/lws/alice/root/private.txt', '/lws/alice/root/missing.txt', '/lws/alice/root/missing/deeper/'] as $path) {
      $document = $this->assertMetadata($this->metadata($path), self::BASE . $path);
      unset($document['resource']);
      $documents[] = $document;
      foreach ($authorizations as $authorization) {
        $this->assertMetadata($this->metadata($path, ['Authorization' => $authorization]), self::BASE . $path);
      }
    }
    $this->assertCount(1, array_unique(array_map('serialize', $documents)));
  }

  /**
   * Tests URLs that name no protected resource of this site.
   */
  public function testNotFound(): void {
    $paths = [
      // No storage, or none there.
      '/lws/bob/root/',
      '/lws/bob/',
      '/lws/',
      '/lws/oauth/token',
      // Nothing can be there.
      '/lws/alice/elsewhere',
      '/lws/alice/root/a%2Fb',
      '/lws/alice/root/../',
      // Outside the LWS URL space.
      '/node/1',
      '/',
      '',
    ];
    foreach ($paths as $path) {
      $response = $this->metadata($path);
      $this->assertSame(404, $response->getStatusCode(), $path);
      $this->assertSame('application/problem+json', $response->headers->get('Content-Type'), $path);
    }
    // A storage without an authorization server has no metadata to give.
    $this->config('lws_authz.settings')->set('authorization_server', 'gone')->save();
    $this->assertSame(404, $this->metadata('/lws/alice/root/')->getStatusCode());
    $this->assertSame(503, $this->send('GET', '/lws/alice/root/')->getStatusCode());
  }

  /**
   * Tests that browsers on other origins may read it.
   */
  public function testCors(): void {
    $response = $this->metadata('/lws/alice/root/', ['Origin' => 'https://app.example']);
    $this->assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));

    $preflight = $this->metadata('/lws/alice/root/', [
      'Origin' => 'https://app.example',
      'Access-Control-Request-Method' => 'GET',
    ], 'OPTIONS');
    $this->assertSame('*', $preflight->headers->get('Access-Control-Allow-Origin'));
    $this->assertSame('GET, HEAD', $preflight->headers->get('Access-Control-Allow-Methods'));
    $this->assertSame('600', $preflight->headers->get('Access-Control-Max-Age'));
  }

  /**
   * Tests discovery as a client performs it, from a real 401.
   *
   * LWS Core, Discovery Validation by a Client: the metadata is on the
   * request's origin, its resource is identical to the URL requested, and the
   * storage the 401 links to has its root on that origin. The first
   * authorization server it names is the one the storage trusts.
   */
  public function testClientDiscovery(): void {
    foreach (['/lws/alice/root/', '/lws/alice/root/caf%C3%A9.txt', '/lws/alice/root/?z=1&a=2'] as $path) {
      $url = self::BASE . $path;
      $response = $this->send('GET', $path);
      $this->assertSame(401, $response->getStatusCode());
      $challenge = WwwAuthenticate::parse((string) $response->headers->get('WWW-Authenticate'))[0];
      $metadataUrl = (string) $challenge->resourceMetadata();
      $this->assertStringStartsWith(self::BASE . '/', $metadataUrl);
      $links = LinkHeader::parse(array_map('strval', $response->headers->all('link')), $url);
      $storage = (string) Link::first($links, LinkRelation::STORAGE)?->href;
      $this->assertSame(self::STORAGE, $storage);

      $metadata = ProtectedResourceMetadata::parse($this->json($this->send('GET', substr($metadataUrl, strlen(self::BASE)))));
      $this->assertTrue($metadata->describes($url), $path);
      $this->assertSame(self::ISSUER, $metadata->authorizationServers[0]);
      $description = StorageDescription::parse($this->json($this->send('GET', substr($storage, strlen(self::BASE)))), $storage);
      $this->assertStringStartsWith(self::BASE . '/', $description->storageRoot());
    }
  }

}
