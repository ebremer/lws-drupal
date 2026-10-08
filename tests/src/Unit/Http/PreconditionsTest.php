<?php

declare(strict_types=1);

namespace Drupal\Tests\lws\Unit\Http;

use Drupal\lws\Http\Preconditions;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests conditional request evaluation (RFC 9110 §13.2.2).
 */
#[CoversClass(Preconditions::class)]
#[Group('lws')]
final class PreconditionsTest extends UnitTestCase {

  private const MODIFIED = 1767225600;

  private const BEFORE = 'Wed, 31 Dec 2025 00:00:00 GMT';

  private const AT = 'Thu, 01 Jan 2026 00:00:00 GMT';

  private const AFTER = 'Fri, 02 Jan 2026 00:00:00 GMT';

  /**
   * Requests, the state they meet, and the outcome.
   *
   * @return array<string, array{string, array<string, string>, string|null, int|null}>
   *   Method, headers, current entity tag (NULL: no resource), outcome.
   */
  public static function cases(): array {
    return [
      'no conditions' => ['GET', [], 'abc', NULL],
      'If-Match matches' => ['PUT', ['If-Match' => '"abc"'], 'abc', NULL],
      'If-Match among others' => ['PUT', ['If-Match' => '"x", "abc"'], 'abc', NULL],
      'If-Match stale' => ['PUT', ['If-Match' => '"old"'], 'abc', 412],
      'If-Match weak never matches' => ['PUT', ['If-Match' => 'W/"abc"'], 'abc', 412],
      'If-Match star, existing' => ['PUT', ['If-Match' => '*'], 'abc', NULL],
      'If-Match star, missing' => ['PUT', ['If-Match' => '*'], NULL, 412],
      'If-Match on a read' => ['GET', ['If-Match' => '"old"'], 'abc', 412],
      'If-None-Match matches on a read' => ['GET', ['If-None-Match' => '"abc"'], 'abc', 304],
      'If-None-Match weak matches on a read' => ['GET', ['If-None-Match' => 'W/"abc"'], 'abc', 304],
      'If-None-Match matches on HEAD' => ['HEAD', ['If-None-Match' => '"abc"'], 'abc', 304],
      'If-None-Match matches on a write' => ['PUT', ['If-None-Match' => '"abc"'], 'abc', 412],
      'If-None-Match stale' => ['GET', ['If-None-Match' => '"old"'], 'abc', NULL],
      'If-None-Match star, existing write' => ['PUT', ['If-None-Match' => '*'], 'abc', 412],
      'If-None-Match star, missing' => ['PUT', ['If-None-Match' => '*'], NULL, NULL],
      'If-Unmodified-Since after' => ['PUT', ['If-Unmodified-Since' => self::AFTER], 'abc', NULL],
      'If-Unmodified-Since same' => ['GET', ['If-Unmodified-Since' => self::AT], 'abc', NULL],
      'If-Unmodified-Since before' => ['GET', ['If-Unmodified-Since' => self::BEFORE], 'abc', 412],
      'If-Unmodified-Since ignored with If-Match' => [
        'PUT',
        ['If-Match' => '"abc"', 'If-Unmodified-Since' => self::BEFORE],
        'abc',
        NULL,
      ],
      'If-Unmodified-Since invalid is ignored' => ['PUT', ['If-Unmodified-Since' => 'yesterday'], 'abc', NULL],
      'If-Modified-Since same' => ['GET', ['If-Modified-Since' => self::AT], 'abc', 304],
      'If-Modified-Since before' => ['GET', ['If-Modified-Since' => self::BEFORE], 'abc', NULL],
      'If-Modified-Since ignored on a write' => ['PUT', ['If-Modified-Since' => self::AT], 'abc', NULL],
      'If-Modified-Since ignored with If-None-Match' => [
        'GET',
        ['If-None-Match' => '"old"', 'If-Modified-Since' => self::AT],
        'abc',
        NULL,
      ],
      'If-Match first, then If-None-Match' => ['GET', ['If-Match' => '"abc"', 'If-None-Match' => '"abc"'], 'abc', 304],
    ];
  }

  /**
   * Tests the evaluation.
   *
   * @param string $method
   *   The request method.
   * @param array<string, string> $headers
   *   The conditional headers.
   * @param string|null $etag
   *   The current entity tag.
   * @param int|null $outcome
   *   The expected status, or NULL to go ahead.
   */
  #[DataProvider('cases')]
  public function testEvaluate(string $method, array $headers, ?string $etag, ?int $outcome): void {
    $request = Request::create('https://storage.example/lws/alice/root/a.txt', $method);
    foreach ($headers as $name => $value) {
      $request->headers->set($name, $value);
    }
    $this->assertSame($outcome, Preconditions::evaluate($request, $etag, $etag === NULL ? NULL : self::MODIFIED));
  }

}
