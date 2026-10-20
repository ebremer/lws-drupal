<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_storage\Kernel;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\lws\Hook\LwsRequirements;
use Drupal\lws\Http\RequestBody;
use Drupal\lws_authz\Hook\LwsAuthzRequirements;
use Drupal\lws_storage\Hook\LwsStorageRequirements;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the status report entries of the LWS modules.
 */
#[Group('lws')]
#[RunTestsInSeparateProcesses]
final class StatusReportTest extends LwsStorageKernelTestBase {

  /**
   * The severity of one entry.
   *
   * @phpstan-impure
   */
  private function severity(object $hooks, string $key): RequirementSeverity {
    assert(method_exists($hooks, 'runtime'));
    $requirements = $hooks->runtime();
    $this->assertArrayHasKey($key, $requirements);
    return $requirements[$key]['severity'];
  }

  /**
   * Tests the entry for HTTPS.
   */
  public function testHttps(): void {
    $this->assertSame(RequirementSeverity::OK, $this->severity($this->container->get(LwsRequirements::class), 'lws_https'));
    $this->config('lws.settings')->set('base_url', 'http://localhost:8899')->save();
    $this->assertSame(RequirementSeverity::Warning, $this->severity($this->container->get(LwsRequirements::class), 'lws_https'));
    $this->config('lws.settings')->set('base_url', 'http://storage.example')->save();
    $this->assertSame(RequirementSeverity::Error, $this->severity($this->container->get(LwsRequirements::class), 'lws_https'));
  }

  /**
   * Tests the entries for content.
   */
  public function testContent(): void {
    $hooks = $this->container->get(LwsStorageRequirements::class);
    $this->assertSame(RequirementSeverity::OK, $this->severity($hooks, 'lws_storage_private_files'));
    // No limit, then one PHP does not let a POST reach.
    $this->assertSame(RequirementSeverity::Warning, $this->severity($hooks, 'lws_storage_upload_size'));
    $post = RequestBody::postMaxSize();
    if ($post > 0) {
      $this->config('lws_storage.settings')->set('max_upload_bytes', $post + 1)->save();
      $this->assertSame(RequirementSeverity::Warning, $this->severity($hooks, 'lws_storage_upload_size'));
    }
    $this->config('lws_storage.settings')->set('max_upload_bytes', 1024)->save();
    $this->assertSame(RequirementSeverity::OK, $this->severity($hooks, 'lws_storage_upload_size'));

    // Content the web server serves itself bypasses LWS policy.
    $this->config('lws_storage.settings')->set('scheme', 'public')->save();
    $this->assertSame(RequirementSeverity::Error, $this->severity($hooks, 'lws_storage_private_files'));
    $this->config('lws_storage.settings')->set('scheme', 'nowhere')->save();
    $this->assertSame(RequirementSeverity::Error, $this->severity($hooks, 'lws_storage_private_files'));
  }

  /**
   * Tests the entry for the metadata of this site's authorization server.
   */
  public function testMetadata(): void {
    $responses = new MockHandler([
      new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['issuer' => self::BASE])),
      new Response(404),
      new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['issuer' => 'https://other.example'])),
      new ConnectException('Connection refused', new Request('GET', self::BASE)),
    ]);
    $this->container->set('http_client', new Client(['handler' => HandlerStack::create($responses)]));
    $hooks = $this->container->get(LwsAuthzRequirements::class);
    $this->assertSame(RequirementSeverity::OK, $this->severity($hooks, 'lws_authz_metadata'));
    $this->assertSame(RequirementSeverity::Warning, $this->severity($hooks, 'lws_authz_metadata'));
    $this->assertSame(RequirementSeverity::Warning, $this->severity($hooks, 'lws_authz_metadata'));
    $this->assertSame(RequirementSeverity::Warning, $this->severity($hooks, 'lws_authz_metadata'));
    $this->assertSame(self::BASE . '/.well-known/lws-configuration', (string) $responses->getLastRequest()?->getUri());
  }

  /**
   * Tests that entry for a site below the root of its host.
   *
   * The issuer keeps the path, so a client asks for the metadata with the
   * well-known segment before it (RFC 8414 §3.1), not at the site.
   */
  public function testMetadataBelowRoot(): void {
    $base = self::BASE . '/targets/drupal';
    $this->config('lws.settings')->set('base_url', $base . '/')->save();
    $responses = new MockHandler([
      new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['issuer' => $base])),
      new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['issuer' => self::BASE])),
    ]);
    $this->container->set('http_client', new Client(['handler' => HandlerStack::create($responses)]));
    $hooks = $this->container->get(LwsAuthzRequirements::class);
    $this->assertInstanceOf(LwsAuthzRequirements::class, $hooks);
    $this->assertSame(RequirementSeverity::OK, $this->severity($hooks, 'lws_authz_metadata'));
    $this->assertSame(self::BASE . '/.well-known/lws-configuration/targets/drupal', (string) $responses->getLastRequest()?->getUri());
    // The origin's metadata is another issuer's; the entry says where to send
    // the URL clients ask for.
    $requirements = $hooks->runtime();
    $this->assertSame(RequirementSeverity::Warning, $requirements['lws_authz_metadata']['severity']);
    $this->assertStringContainsString('send ' . self::BASE . '/.well-known/lws-configuration/targets/drupal to ' . $base . '/.well-known/lws-configuration,', (string) $requirements['lws_authz_metadata']['description']);
    // The site's own server is fine below the root, and names its issuer.
    $this->assertSame(RequirementSeverity::OK, $requirements['lws_authz_local_server']['severity']);
    $this->assertStringStartsWith($base . ';', (string) $requirements['lws_authz_local_server']['value']);
  }

  /**
   * Tests the entry for the protected resource metadata of storages.
   *
   * The report asks for that of the first storage, as a client would.
   */
  public function testResourceMetadata(): void {
    $document = [
      'resource' => self::BASE . '/lws/alice/',
      'authorization_servers' => [self::ISSUER],
      'bearer_methods_supported' => ['header'],
    ];
    $json = ['Content-Type' => 'application/json'];
    $responses = new MockHandler([
      new Response(200, $json, (string) json_encode($document)),
      new Response(404),
      new Response(200, $json, (string) json_encode(['resource' => self::BASE . '/lws/alice/root/'] + $document)),
      new Response(200, $json, (string) json_encode(['authorization_servers' => ['https://other.example']] + $document)),
    ]);
    $this->container->set('http_client', new Client(['handler' => HandlerStack::create($responses)]));
    $hooks = $this->container->get(LwsStorageRequirements::class);
    $this->assertInstanceOf(LwsStorageRequirements::class, $hooks);
    // Without a storage there is nothing to ask about.
    $this->assertArrayNotHasKey('lws_storage_resource_metadata', $hooks->runtime());

    $this->storages->createStorage('alice', 'Alice');
    $this->assertSame(RequirementSeverity::OK, $this->severity($hooks, 'lws_storage_resource_metadata'));
    $this->assertSame(self::BASE . '/.well-known/oauth-protected-resource/lws/alice/', (string) $responses->getLastRequest()?->getUri());
    $requirements = $hooks->runtime();
    $this->assertSame(RequirementSeverity::Warning, $requirements['lws_storage_resource_metadata']['severity']);
    $this->assertStringContainsString('passes /.well-known/oauth-protected-resource/… to Drupal', (string) $requirements['lws_storage_resource_metadata']['description']);
    $this->assertSame(RequirementSeverity::Warning, $this->severity($hooks, 'lws_storage_resource_metadata'));
    $this->assertSame(RequirementSeverity::Warning, $this->severity($hooks, 'lws_storage_resource_metadata'));

    // A storage without an authorization server has no metadata.
    $this->config('lws_authz.settings')->set('authorization_server', 'gone')->save();
    $this->assertArrayNotHasKey('lws_storage_resource_metadata', $hooks->runtime());
  }

  /**
   * Tests that entry for a site below the root of its host.
   *
   * Clients ask for the metadata at the well-known segment before the base
   * URL's path, where the web server or a proxy must send it to the site.
   */
  public function testResourceMetadataBelowRoot(): void {
    $base = self::BASE . '/targets/drupal';
    $this->config('lws.settings')->set('base_url', $base . '/')->save();
    $this->storages->createStorage('alice', 'Alice');
    $document = ['resource' => $base . '/lws/alice/', 'authorization_servers' => [self::ISSUER]];
    $responses = new MockHandler([
      new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($document)),
      new Response(404),
    ]);
    $this->container->set('http_client', new Client(['handler' => HandlerStack::create($responses)]));
    $hooks = $this->container->get(LwsStorageRequirements::class);
    $this->assertInstanceOf(LwsStorageRequirements::class, $hooks);
    $this->assertSame(RequirementSeverity::OK, $this->severity($hooks, 'lws_storage_resource_metadata'));
    $this->assertSame(self::BASE . '/.well-known/oauth-protected-resource/targets/drupal/lws/alice/', (string) $responses->getLastRequest()?->getUri());
    $requirements = $hooks->runtime();
    $this->assertSame(RequirementSeverity::Warning, $requirements['lws_storage_resource_metadata']['severity']);
    $this->assertStringContainsString('send ' . self::BASE . '/.well-known/oauth-protected-resource/targets/drupal/… to ' . $base . '/.well-known/oauth-protected-resource/…,', (string) $requirements['lws_storage_resource_metadata']['description']);
  }

}
