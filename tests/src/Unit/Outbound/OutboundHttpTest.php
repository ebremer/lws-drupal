<?php

declare(strict_types=1);

namespace Drupal\Tests\lws\Unit\Outbound;

use Drupal\Core\Site\Settings;
use Drupal\lws\Outbound\HostResolverInterface;
use Drupal\lws\Outbound\OutboundHttp;
use Drupal\lws\Outbound\OutboundHttpException;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\RequestInterface;

/**
 * Tests the guard on outbound requests.
 */
#[CoversClass(OutboundHttp::class)]
#[Group('lws')]
final class OutboundHttpTest extends UnitTestCase {

  /**
   * The responses the mock server gives, in order.
   */
  private MockHandler $mock;

  /**
   * The requests sent, with their options.
   *
   * @var list<array{request: \Psr\Http\Message\RequestInterface, options: array<string, mixed>}>
   */
  private array $sent = [];

  /**
   * The addresses each host name resolves to.
   *
   * @var array<string, list<string>>
   */
  private array $dns = [
    'as.example' => ['93.184.215.14'],
    'dual.example' => ['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c'],
    'internal.example' => ['10.0.0.5'],
    // A rebinding attacker's name: one public and one private address.
    'rebind.example' => ['93.184.215.14', '127.0.0.1'],
  ];

  /**
   * Builds the guard over a mock server.
   *
   * @param array<string, mixed> $settings
   *   Site settings.
   */
  private function http(array $settings = []): OutboundHttp {
    $this->mock = new MockHandler();
    $stack = HandlerStack::create($this->mock);
    $stack->push(fn (callable $handler) => function (RequestInterface $request, array $options) use ($handler) {
      $this->sent[] = ['request' => $request, 'options' => $options];
      return $handler($request, $options);
    });
    $resolver = new class($this->dns) implements HostResolverInterface {

      /**
       * @param array<string, list<string>> $dns
       *   Host names and their addresses.
       */
      public function __construct(private readonly array $dns) {}

      /**
       * {@inheritdoc}
       */
      public function resolve(string $host): array {
        return $this->dns[$host] ?? [];
      }

    };
    return new OutboundHttp(new Client(['handler' => $stack]), $resolver, new Settings($settings));
  }

  /**
   * Asserts that fetching a URL is refused before anything is sent.
   */
  private function assertRefused(OutboundHttp $http, string $url, string $reason): void {
    try {
      $http->get($url);
      $this->fail("$url was fetched");
    }
    catch (OutboundHttpException $e) {
      $this->assertStringContainsString($reason, $e->getMessage());
    }
  }

  /**
   * Tests a fetch from a public host, pinned to its checked addresses.
   */
  public function testPublicHost(): void {
    $http = $this->http();
    $this->mock->append(new Response(200, ['Content-Type' => 'application/json'], '{"issuer":"https://as.example"}'));
    $response = $http->get('https://as.example/.well-known/lws-configuration');
    $this->assertSame(200, $response->status);
    $this->assertSame(['issuer' => 'https://as.example'], $response->json());

    $options = $this->sent[0]['options'];
    $this->assertSame(['as.example:443:93.184.215.14'], $options['curl'][CURLOPT_RESOLVE]);
    // No kept-alive connection can bypass the pin.
    $this->assertTrue($options['curl'][CURLOPT_FRESH_CONNECT]);
    $this->assertTrue($options['curl'][CURLOPT_FORBID_REUSE]);
    $this->assertFalse($options['allow_redirects']);
    $this->assertSame(OutboundHttp::TIMEOUT, $options['timeout']);
    $this->assertSame('application/json', $this->sent[0]['request']->getHeaderLine('Accept'));
  }

  /**
   * Tests that every address of a host is pinned, with IPv6 in brackets.
   */
  public function testAllAddressesPinned(): void {
    $http = $this->http();
    $this->mock->append(new Response(200, [], '{}'));
    $http->get('https://dual.example:8443/jwks');
    $this->assertSame(['dual.example:8443:93.184.215.14,[2606:2800:21f:cb07:6820:80da:af6b:8b2c]'], $this->sent[0]['options']['curl'][CURLOPT_RESOLVE]);
  }

  /**
   * Tests the URLs refused before any request.
   */
  public function testRefusedUrls(): void {
    $http = $this->http();
    $this->assertRefused($http, 'http://as.example/jwks', 'only HTTPS');
    $this->assertRefused($http, 'https://internal.example/jwks', 'not a public address');
    $this->assertRefused($http, 'https://rebind.example/jwks', 'not a public address');
    $this->assertRefused($http, 'https://unknown.example/jwks', 'does not resolve');
    $this->assertRefused($http, 'https://127.0.0.1/jwks', 'not a public address');
    $this->assertRefused($http, 'https://[::1]/jwks', 'not a public address');
    $this->assertRefused($http, 'https://169.254.169.254/latest/meta-data/', 'not a public address');
    $this->assertRefused($http, 'https://user:secret@as.example/jwks', 'no user information');
    $this->assertRefused($http, '/relative', 'not an absolute URL');
    $this->assertSame([], $this->sent);
  }

  /**
   * Tests that a public IP address is fetched without a pin.
   */
  public function testAddressLiteral(): void {
    $http = $this->http();
    $this->mock->append(new Response(200, [], '{}'));
    $http->get('https://93.184.215.14/jwks');
    $this->assertArrayNotHasKey(CURLOPT_RESOLVE, $this->sent[0]['options']['curl']);
  }

  /**
   * Tests the development allow-list.
   */
  public function testAllowList(): void {
    $http = $this->http(['lws_outbound_allowlist' => ['http://localhost:8080']]);
    $this->mock->append(new Response(200, [], '{}'));
    $http->get('http://localhost:8080/jwks');
    $this->assertArrayNotHasKey(CURLOPT_RESOLVE, $this->sent[0]['options']['curl']);
    // Only the listed origin.
    $this->assertRefused($http, 'http://localhost:8081/jwks', 'only HTTPS');
    $this->assertRefused($http, 'https://localhost:8080/jwks', 'does not resolve');
  }

  /**
   * Tests that redirects are followed, and each target is checked.
   */
  public function testRedirects(): void {
    $http = $this->http();
    $this->mock->append(
      new Response(302, ['Location' => '/keys']),
      new Response(301, ['Location' => 'https://dual.example/keys']),
      new Response(200, [], '{"keys":[]}'),
    );
    $response = $http->get('https://as.example/jwks');
    $this->assertSame('https://dual.example/keys', $response->url);
    $this->assertSame(['as.example:443:93.184.215.14'], $this->sent[1]['options']['curl'][CURLOPT_RESOLVE]);
    $this->assertSame('https://as.example/keys', (string) $this->sent[1]['request']->getUri());

    // A redirect to a private address is refused.
    $this->mock->append(new Response(302, ['Location' => 'https://internal.example/']));
    $this->assertRefused($http, 'https://as.example/jwks', 'not a public address');

    // So is a fourth redirect.
    for ($i = 0; $i <= OutboundHttp::MAX_REDIRECTS; $i++) {
      $this->mock->append(new Response(302, ['Location' => '/again']));
    }
    $this->assertRefused($http, 'https://as.example/jwks', 'redirects');
  }

  /**
   * Tests the size limit, declared and actual.
   */
  public function testSizeLimit(): void {
    $http = $this->http();
    $this->mock->append(new Response(200, ['Content-Length' => (string) (OutboundHttp::MAX_BYTES + 1)], '{}'));
    $this->assertRefused($http, 'https://as.example/jwks', 'larger than');
    $this->mock->append(new Response(200, [], str_repeat(' ', OutboundHttp::MAX_BYTES + 1)));
    $this->assertRefused($http, 'https://as.example/jwks', 'larger than');
    $this->mock->append(new Response(200, [], str_repeat(' ', OutboundHttp::MAX_BYTES - 2) . '{}'));
    $this->assertSame(OutboundHttp::MAX_BYTES, strlen($http->get('https://as.example/jwks')->body));
  }

  /**
   * Tests responses that are not JSON objects.
   */
  public function testJson(): void {
    $http = $this->http();
    $this->mock->append(new Response(404, [], '{}'), new Response(200, [], 'not JSON'), new Response(200, [], '[1]'));
    foreach (['answered 404', 'did not return JSON', 'not return a JSON object'] as $reason) {
      try {
        $http->get('https://as.example/jwks')->json();
        $this->fail('Accepted a response that is not a JSON object');
      }
      catch (OutboundHttpException $e) {
        $this->assertStringContainsString($reason, $e->getMessage());
      }
    }
  }

}
