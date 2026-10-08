<?php

declare(strict_types=1);

namespace Drupal\Tests\lws\Unit\Routing;

use Drupal\lws\Routing\LwsPathProcessor;
use Drupal\lws\Routing\LwsUrlParser;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the mapping of the LWS URL space onto internal paths.
 */
#[CoversClass(LwsPathProcessor::class)]
#[Group('lws')]
final class LwsPathProcessorTest extends UnitTestCase {

  /**
   * Processes a request's own path, as the router does.
   */
  private function process(string $rawPath, ?string $path = NULL): string {
    $processor = new LwsPathProcessor(new LwsUrlParser($this->getConfigFactoryStub(['lws.settings' => ['prefix' => '/lws']])));
    $request = Request::create('https://storage.example' . $rawPath);
    return $processor->processInbound($path ?? rtrim($request->getPathInfo(), '/'), $request);
  }

  /**
   * Tests the internal path of each area.
   */
  public function testInternalPaths(): void {
    $this->assertSame('/_lws/description', $this->process('/lws/alice/'));
    $this->assertSame('/_lws/resource', $this->process('/lws/alice/root/'));
    $this->assertSame('/_lws/resource', $this->process('/lws/alice/root/a%20b/c'));
    $this->assertSame('/_lws/meta', $this->process('/lws/alice/meta/0b5c3a5e-6a3e-4c1f-9d2e-3f1a2b3c4d5e'));
    $this->assertSame('/_lws/unknown', $this->process('/lws/alice/nothing/'));
    $this->assertSame('/_lws/unknown', $this->process('/lws/alice/root/../x'));
  }

  /**
   * Tests that paths outside the URL space are unchanged.
   */
  public function testOutside(): void {
    $this->assertSame('/node/1', $this->process('/node/1'));
    $this->assertSame('/lws/oauth/token', $this->process('/lws/oauth/token'));
  }

  /**
   * Tests that a lookup of a path other than the request's is unchanged.
   */
  public function testOtherPath(): void {
    $this->assertSame('/lws/bob/root', $this->process('/lws/alice/root/', '/lws/bob/root'));
  }

}
