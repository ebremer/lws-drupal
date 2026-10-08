<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_authz\Unit;

use Drupal\lws_authz\Plugin\LwsAuthenticationSuite\OpenIdConnect;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests reading a subject's OpenID Provider from its document (§5).
 */
#[CoversClass(OpenIdConnect::class)]
#[Group('lws')]
final class OpenIdConnectTest extends UnitTestCase {

  private const OP = 'https://op.example';

  /**
   * Tests documents that name the provider, or do not.
   *
   * @param array<string, mixed> $document
   *   The document.
   * @param bool $names
   *   Whether it names https://op.example.
   */
  #[DataProvider('documentProvider')]
  public function testNamesProvider(array $document, bool $names): void {
    $this->assertSame($names, OpenIdConnect::namesProvider($document + ['id' => 'https://id.example/alice'], self::OP));
  }

  /**
   * Data provider for testNamesProvider().
   *
   * @return array<string, array{array<string, mixed>, bool}>
   *   The document and whether it names the provider.
   */
  public static function documentProvider(): array {
    $full = 'https://www.w3.org/ns/lws#OpenIdProvider';
    $lws = 'https://www.w3.org/ns/lws/v1';
    $service = static fn (mixed $type, mixed $endpoint = self::OP): array => [
      ['type' => $type, 'serviceEndpoint' => $endpoint],
    ];
    return [
      'the full IRI' => [['service' => $service($full)], TRUE],
      'one service, not in a list' => [['service' => $service($full)[0]], TRUE],
      'one of several types and endpoints' => [
        ['service' => $service(['Other', $full], ['https://x.example', self::OP])],
        TRUE,
      ],
      'the term, with the LWS context' => [
        ['@context' => ['https://www.w3.org/ns/cid/v1', $lws], 'service' => $service('OpenIdProvider')],
        TRUE,
      ],
      'a compact IRI the context defines' => [
        ['@context' => [['lws' => 'https://www.w3.org/ns/lws#']], 'service' => $service('lws:OpenIdProvider')],
        TRUE,
      ],
      'a term the context defines' => [['@context' => [['OP' => $full]], 'service' => $service('OP')], TRUE],
      'the term, without the LWS context' => [['service' => $service('OpenIdProvider')], FALSE],
      'a compact IRI nobody defined' => [['service' => $service('lws:OpenIdProvider')], FALSE],
      'another provider' => [['service' => $service($full, 'https://rogue.example')], FALSE],
      'the issuer with a slash' => [['service' => $service($full, self::OP . '/')], FALSE],
      'another service type' => [['service' => $service('LinkedDomains')], FALSE],
      'no services' => [[], FALSE],
      'junk' => [['service' => 'nonsense'], FALSE],
    ];
  }

}
