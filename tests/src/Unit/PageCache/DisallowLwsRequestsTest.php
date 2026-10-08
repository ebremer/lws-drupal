<?php

declare(strict_types=1);

namespace Drupal\Tests\lws\Unit\PageCache;

use Drupal\Core\PageCache\RequestPolicyInterface;
use Drupal\lws\PageCache\DisallowLwsRequests;
use Drupal\lws\Routing\LwsUrlParser;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the page cache request policy.
 */
#[CoversClass(DisallowLwsRequests::class)]
#[Group('lws')]
final class DisallowLwsRequestsTest extends UnitTestCase {

  /**
   * Requests and the expected policy result.
   *
   * @return array<string, array{string, string|null, string|null}>
   *   Path, Authorization header and policy result.
   */
  public static function requests(): array {
    return [
      'LWS resource' => ['/lws/alice/root/', NULL, RequestPolicyInterface::DENY],
      'LWS unknown path' => ['/lws/alice/nothing/', NULL, RequestPolicyInterface::DENY],
      'bearer token anywhere' => ['/node/1', 'Bearer abc.def.ghi', RequestPolicyInterface::DENY],
      'bearer scheme is case-insensitive' => ['/node/1', 'bearer abc', RequestPolicyInterface::DENY],
      'DPoP token' => ['/node/1', 'DPoP abc', RequestPolicyInterface::DENY],
      'other page' => ['/node/1', NULL, NULL],
      'basic credentials are left to basic_auth' => ['/node/1', 'Basic YTpi', NULL],
      'reserved LWS path' => ['/lws/oauth/token', NULL, NULL],
    ];
  }

  /**
   * Tests the policy.
   */
  #[DataProvider('requests')]
  public function testCheck(string $path, ?string $authorization, ?string $expected): void {
    $policy = new DisallowLwsRequests(new LwsUrlParser($this->getConfigFactoryStub(['lws.settings' => ['prefix' => '/lws']])));
    $request = Request::create('https://storage.example' . $path);
    if ($authorization !== NULL) {
      $request->headers->set('Authorization', $authorization);
    }
    $this->assertSame($expected, $policy->check($request));
  }

}
