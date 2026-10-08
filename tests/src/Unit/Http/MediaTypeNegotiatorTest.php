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
   * Tests that a requested profile is echoed when the server conforms to it.
   */
  public function testProfile(): void {
    $lws = 'https://www.w3.org/ns/lws/v1';
    $type = MediaTypeNegotiator::negotiate('application/ld+json; profile="' . $lws . '"', self::OFFERED);
    $this->assertNotNull($type);
    $this->assertSame('application/ld+json; profile="' . $lws . '"', $type->contentType([$lws]));
    $this->assertSame('application/ld+json', $type->contentType());

    $other = MediaTypeNegotiator::negotiate('application/ld+json; profile="https://other.example/"', self::OFFERED);
    $this->assertSame('application/ld+json', $other?->contentType([$lws]));
  }

}
