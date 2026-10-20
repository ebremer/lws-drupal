<?php

declare(strict_types=1);

namespace Drupal\Tests\lws\Unit\Http;

use Drupal\lws\Http\MediaTypeNegotiator;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests content negotiation.
 */
#[CoversClass(MediaTypeNegotiator::class)]
#[Group('lws')]
final class MediaTypeNegotiatorTest extends UnitTestCase {

  private const OFFERED = ['application/lws+json', 'application/ld+json', 'application/json'];

  /**
   * The LWS profile.
   */
  private const LWS = 'https://www.w3.org/ns/lws/v1';

  /**
   * Accept headers and the type chosen from OFFERED.
   *
   * @return array<string, array{string|null, string|null}>
   *   Accept header and chosen type.
   */
  public static function cases(): array {
    return [
      'no Accept header' => [NULL, 'application/lws+json'],
      'empty Accept header' => ['', 'application/lws+json'],
      'anything' => ['*/*', 'application/lws+json'],
      'exact' => ['application/json', 'application/json'],
      'case-insensitive' => ['Application/LD+JSON', 'application/ld+json'],
      'quality decides' => ['application/lws+json;q=0.5, application/json', 'application/json'],
      'ties go to the server' => ['application/json, application/ld+json', 'application/ld+json'],
      'subtype wildcard' => ['application/*', 'application/lws+json'],
      'specific range overrides wildcard' => ['application/*, application/lws+json;q=0', 'application/ld+json'],
      'browser default' => ['text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'application/lws+json'],
      'nothing acceptable' => ['text/turtle', NULL],
      'all excluded' => ['*/*;q=0', NULL],
    ];
  }

  /**
   * Tests the chosen type.
   */
  #[DataProvider('cases')]
  public function testNegotiate(?string $accept, ?string $expected): void {
    $this->assertSame($expected, MediaTypeNegotiator::negotiate($accept, self::OFFERED)?->type);
  }

  /**
   * Accept headers asking for profiles, and the Content-Type chosen.
   *
   * Negotiated over OFFERED, with application/ld+json produced in the LWS
   * profile only, as a container's is.
   *
   * @return array<string, array{string, string|null}>
   *   Accept header and Content-Type, or NULL for none.
   */
  public static function profileCases(): array {
    $lws = self::LWS;
    $in = 'application/ld+json; profile="' . $lws . '"';
    $other = 'application/ld+json; profile="https://example.org/a"';
    return [
      'the LWS profile' => [$in, $in],
      'unquoted' => ['application/ld+json;profile=' . $lws, $in],
      'parameter name in capitals' => ['application/ld+json; PROFILE="' . $lws . '"', $in],
      'one of a list' => ['application/ld+json; profile="https://example.org/a ' . $lws . ' https://a.example/"', $in],
      'another profile' => [$other, NULL],
      'a list without it' => ['application/ld+json; profile="https://example.org/a https://example.org/b"', NULL],
      'a profile without a value' => ['application/ld+json; profile', NULL],
      'another profile, then plain JSON' => [$other . ', application/json;q=0.5', 'application/json'],
      'another profile, then any JSON-LD' => [$other . ', application/ld+json;q=0.5', 'application/ld+json'],
      'another profile, then anything' => [$other . ', */*;q=0.1', 'application/lws+json'],
      'quality prefers lws+json' => [$in . '; q=0.4, application/lws+json; q=0.5', 'application/lws+json'],
      'quality prefers the profile' => ['application/lws+json; q=0.5, ' . $in, $in],
      'excluded in the profile' => [$in . '; q=0', NULL],
      'without a profile' => ['application/ld+json', 'application/ld+json'],
    ];
  }

  /**
   * Tests negotiation of a type in a profile.
   */
  #[DataProvider('profileCases')]
  public function testProfile(string $accept, ?string $expected): void {
    $this->assertSame($expected, MediaTypeNegotiator::negotiate($accept, self::OFFERED, ['application/ld+json' => [self::LWS]])?->contentType());
  }

  /**
   * Tests types for which profiles play no part, or which are in none.
   */
  public function testNoProfile(): void {
    $accept = 'application/ld+json; profile="' . self::LWS . '"';
    // Without profiles, as for a storage description, the parameter is not
    // looked at, and not echoed.
    $this->assertSame('application/ld+json', MediaTypeNegotiator::negotiate($accept, self::OFFERED)?->contentType());
    $this->assertSame('application/ld+json', MediaTypeNegotiator::negotiate('application/ld+json; profile="https://example.org/a"', self::OFFERED)?->contentType());
    // A type produced in no profile is not selected by a range that asks for
    // one, as for a subscription.
    $none = ['application/ld+json' => []];
    $this->assertNull(MediaTypeNegotiator::negotiate($accept, self::OFFERED, $none));
    $this->assertSame('application/json', MediaTypeNegotiator::negotiate($accept . ', application/json;q=0.1', self::OFFERED, $none)?->contentType());
    $this->assertSame('application/ld+json', MediaTypeNegotiator::negotiate('application/ld+json', self::OFFERED, $none)?->contentType());
    // Other types take no profile; the parameter plays no part for them.
    $this->assertSame('application/lws+json', MediaTypeNegotiator::negotiate('application/lws+json; profile="https://example.org/a"', self::OFFERED, $none)?->contentType());
  }

}
