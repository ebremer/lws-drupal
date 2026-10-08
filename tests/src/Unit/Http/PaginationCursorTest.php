<?php

declare(strict_types=1);

namespace Drupal\Tests\lws\Unit\Http;

use Drupal\Core\PrivateKey;
use Drupal\lws\Http\PaginationCursor;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests signed keyset cursors.
 */
#[CoversClass(PaginationCursor::class)]
#[Group('lws')]
final class PaginationCursorTest extends UnitTestCase {

  /**
   * Cursors made with a key.
   */
  private function cursors(string $key = 'site key'): PaginationCursor {
    $privateKey = $this->createMock(PrivateKey::class);
    $privateKey->method('get')->willReturn($key);
    return new PaginationCursor($privateKey);
  }

  /**
   * Tests the round trip, for awkward keys too.
   */
  public function testRoundTrip(): void {
    $cursors = $this->cursors();
    foreach (['a.txt', 'Café menu/', "quote\" and \\ slash", ''] as $after) {
      $cursor = $cursors->encode('scope', $after);
      $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $cursor);
      $this->assertSame($after, $cursors->decode('scope', $cursor));
    }
  }

  /**
   * Tests the cursors that do not decode.
   */
  public function testRejected(): void {
    $cursors = $this->cursors();
    $cursor = $cursors->encode('scope', 'a.txt');
    [$payload, $signature] = explode('.', $cursor);
    $this->assertNull($cursors->decode('other scope', $cursor));
    $this->assertNull($this->cursors('another key')->decode('scope', $cursor));
    $this->assertNull($cursors->decode('scope', $payload));
    $this->assertNull($cursors->decode('scope', rtrim(strtr(base64_encode('{"a":"z"}'), '+/', '-_'), '=') . '.' . $signature));
    $this->assertNull($cursors->decode('scope', $cursor . '.x'));
    $this->assertNull($cursors->decode('scope', ''));
  }

}
