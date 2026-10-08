<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_storage\Unit;

use Drupal\lws\Routing\ResourceName;
use Drupal\lws_storage\ResourceNames;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests turning identity hints into names.
 */
#[CoversClass(ResourceNames::class)]
#[Group('lws')]
final class ResourceNamesTest extends UnitTestCase {

  /**
   * Hints and the names they give.
   *
   * @return array<string, array{string|null, string|null}>
   *   Hint, name.
   */
  public static function hints(): array {
    return [
      'plain' => ['notes.txt', 'notes.txt'],
      'spaces' => ['shopping list.txt', 'shopping-list.txt'],
      'percent-encoded' => ['caf%C3%A9 menu', 'caf-menu'],
      'unreserved kept' => ['A_b.c~d-e', 'A_b.c~d-e'],
      'leading dot' => ['.profile', 'profile'],
      'traversal' => ['../../etc/passwd', 'etc-passwd'],
      'slashes' => ['a/b/c', 'a-b-c'],
      'runs of dashes' => ['a -- b', 'a-b'],
      'nothing usable' => ['../', NULL],
      'empty' => ['', NULL],
      'none' => [NULL, NULL],
      'long' => [str_repeat('a', 300), str_repeat('a', ResourceNames::MAX_HINT_BYTES)],
    ];
  }

  /**
   * Tests sanitising hints.
   */
  #[DataProvider('hints')]
  public function testFromHint(?string $hint, ?string $name): void {
    $this->assertSame($name, ResourceNames::fromHint($hint));
    if ($name !== NULL) {
      $this->assertNull(ResourceName::creationError($name));
    }
  }

  /**
   * Tests the names tried, in order.
   */
  public function testCandidates(): void {
    $generate = static fn (): string => 'generated';
    $names = iterator_to_array(ResourceNames::candidates('a.txt', FALSE, $generate), FALSE);
    $this->assertSame(['a.txt', 'a-1.txt', 'a-2.txt'], array_slice($names, 0, 3));
    $this->assertSame('a-' . ResourceNames::MAX_SUFFIX . '.txt', $names[ResourceNames::MAX_SUFFIX]);
    $this->assertSame('generated', end($names));

    $this->assertSame(['v1.2/', 'v1.2-1/'], array_slice(iterator_to_array(ResourceNames::candidates('v1.2', TRUE, $generate), FALSE), 0, 2));
    $this->assertSame(['README', 'README-1'], array_slice(iterator_to_array(ResourceNames::candidates('README', FALSE, $generate), FALSE), 0, 2));
    $this->assertSame(['generated/'], iterator_to_array(ResourceNames::candidates(NULL, TRUE, $generate), FALSE));
  }

}
