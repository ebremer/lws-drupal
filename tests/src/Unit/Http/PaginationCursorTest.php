<?php

declare(strict_types=1);

namespace Drupal\Tests\lws\Unit\Http;

use Drupal\Core\PrivateKey;
use Drupal\lws\Http\PaginationCursor;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests encrypted keyset cursors.
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
      $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $cursor);
      $this->assertSame($after, $cursors->decode('scope', $cursor));
      // Nobody can read the key, which may be that of a hidden member.
      if ($after !== '') {
        $this->assertStringNotContainsString($after, (string) base64_decode(strtr($cursor, '-_', '+/')));
      }
    }
    // The same key makes the same cursor, in one listing only.
    $this->assertSame($cursors->encode('scope', 'a.txt'), $cursors->encode('scope', 'a.txt'));
    $this->assertNotSame($cursors->encode('scope', 'a.txt'), $cursors->encode('other scope', 'a.txt'));
    $this->assertNotSame($cursors->encode('scope', 'a.txt'), $cursors->encode('scope', 'b.txt'));
  }

  /**
   * Tests the cursors that do not decode.
   */
  public function testRejected(): void {
    $cursors = $this->cursors();
    $cursor = $cursors->encode('scope', 'a.txt');
    $bytes = base64_decode(strtr($cursor, '-_', '+/'));
    $altered = rtrim(strtr(base64_encode(substr($bytes, 0, -1) . chr(ord($bytes[strlen($bytes) - 1]) ^ 1)), '+/', '-_'), '=');
    $this->assertNull($cursors->decode('other scope', $cursor));
    $this->assertNull($this->cursors('another key')->decode('scope', $cursor));
    $this->assertNull($cursors->decode('scope', $altered));
    $this->assertNull($cursors->decode('scope', substr($cursor, 0, 20)));
    $this->assertNull($cursors->decode('scope', $cursor . '.x'));
    $this->assertNull($cursors->decode('scope', rtrim(strtr(base64_encode('{"a":"z"}'), '+/', '-_'), '=')));
    $this->assertNull($cursors->decode('scope', ''));
  }

}
