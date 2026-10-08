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

}
