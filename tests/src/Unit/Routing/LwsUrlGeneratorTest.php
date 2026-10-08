<?php

declare(strict_types=1);

namespace Drupal\Tests\lws\Unit\Routing;

use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws\Routing\LwsUrlParser;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests canonical URIs in the LWS URL space.
 */
#[CoversClass(LwsUrlGenerator::class)]
#[Group('lws')]
final class LwsUrlGeneratorTest extends UnitTestCase {

  /**
   * Creates a parser and a generator sharing one configuration.
   *
   * @return array{\Drupal\lws\Routing\LwsUrlParser, \Drupal\lws\Routing\LwsUrlGenerator}
   *   The parser and the generator.
   */
  private function services(string $baseUrl = 'https://storage.example', ?Request $request = NULL): array {
    $config = $this->getConfigFactoryStub(['lws.settings' => ['prefix' => '/lws', 'base_url' => $baseUrl]]);
    $stack = new RequestStack();
    if ($request !== NULL) {
      $stack->push($request);
    }
    $parser = new LwsUrlParser($config);
    return [$parser, new LwsUrlGenerator($parser, $config, $stack)];
  }

  /**
   * Tests the storage URI.
   */
  public function testStorageUri(): void {
    [, $urls] = $this->services('https://storage.example/');
    $this->assertSame('https://storage.example/lws/alice/', $urls->storageUri('alice'));
  }

  /**
   * Request paths and the canonical URIs they resolve to.
   *
   * @return array<string, array{string, string}>
   *   Raw path and canonical URI.
   */
  public static function canonicalUris(): array {
    return [
      'description' => ['/lws/alice/', 'https://storage.example/lws/alice/'],
      'root' => ['/lws/alice/root/', 'https://storage.example/lws/alice/root/'],
      'container' => ['/lws/alice/root/Notes/', 'https://storage.example/lws/alice/root/Notes/'],
      'data resource' => ['/lws/alice/root/Notes', 'https://storage.example/lws/alice/root/Notes'],
      'space stays encoded' => ['/lws/alice/root/a%20b', 'https://storage.example/lws/alice/root/a%20b'],
      'unreserved character is decoded' => ['/lws/alice/root/%7Euser/', 'https://storage.example/lws/alice/root/~user/'],
      'hex digits normalized' => ['/lws/alice/root/caf%c3%a9', 'https://storage.example/lws/alice/root/caf%C3%A9'],
      'sub-delimiters kept' => ['/lws/alice/root/a+b=c:d@e', 'https://storage.example/lws/alice/root/a+b=c:d@e'],
      'linkset' => [
        '/lws/alice/meta/0b5c3a5e-6a3e-4c1f-9d2e-3f1a2b3c4d5e',
        'https://storage.example/lws/alice/meta/0b5c3a5e-6a3e-4c1f-9d2e-3f1a2b3c4d5e',
      ],
      'notification service' => ['/lws/alice/notifications/', 'https://storage.example/lws/alice/notifications/'],
      'subscription' => [
        '/lws/alice/notifications/0b5c3a5e-6a3e-4c1f-9d2e-3f1a2b3c4d5e',
        'https://storage.example/lws/alice/notifications/0b5c3a5e-6a3e-4c1f-9d2e-3f1a2b3c4d5e',
      ],
    ];
  }

  /**
   * Tests that parsing and generating give the canonical URI.
   */
  #[DataProvider('canonicalUris')]
  public function testTargetUri(string $path, string $uri): void {
    [$parser, $urls] = $this->services();
    $target = $parser->parse($path);
    $this->assertNotNull($target);
    $this->assertSame($uri, $urls->targetUri($target));
  }

  /**
   * Tests that unknown targets have no URI.
   */
  public function testUnknownHasNoUri(): void {
    [$parser, $urls] = $this->services();
    $target = $parser->parse('/lws/alice/nothing-here/');
    $this->assertNotNull($target);
    $this->assertNull($urls->targetUri($target));
  }

  /**
   * Tests parent containers.
   */
  public function testParentUri(): void {
    [$parser, $urls] = $this->services();
    $root = $parser->parse('/lws/alice/root/');
    $child = $parser->parse('/lws/alice/root/a%20b/c');
    $this->assertNotNull($root);
    $this->assertNotNull($child);
    $this->assertNull($urls->parentUri($root));
    $this->assertSame('https://storage.example/lws/alice/root/a%20b/', $urls->parentUri($child));
  }

  /**
   * Tests the development fallback when no base URL is configured.
   */
  public function testBaseUrlFromRequest(): void {
    [, $urls] = $this->services('', Request::create('https://dev.example/lws/alice/'));
    $this->assertSame('https://dev.example/lws/alice/', $urls->storageUri('alice'));
  }

}
