<?php

declare(strict_types=1);

namespace Drupal\Tests\lws\Unit\Routing;

use Drupal\lws\Routing\ResourceName;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the rules for resource names.
 */
#[CoversClass(ResourceName::class)]
#[Group('lws')]
final class ResourceNameTest extends UnitTestCase {

  /**
   * Names, and whether URLs and new resources may use them.
   *
   * @return array<string, array{string, bool, bool}>
   *   Name, valid in a URL, valid for a new resource.
   */
  public static function names(): array {
    return [
      'plain' => ['notes', TRUE, TRUE],
      'with spaces and UTF-8' => ['Café menu', TRUE, TRUE],
      'longest' => [str_repeat('a', ResourceName::MAX_BYTES), TRUE, TRUE],
      'too long' => [str_repeat('a', ResourceName::MAX_BYTES + 1), TRUE, FALSE],
      'leading dot' => ['.profile', TRUE, FALSE],
      'empty' => ['', FALSE, FALSE],
      'dot' => ['.', FALSE, FALSE],
      'dot-dot' => ['..', FALSE, FALSE],
      'slash' => ['a/b', FALSE, FALSE],
      'control character' => ["a\tb", FALSE, FALSE],
      'invalid UTF-8' => ["\xFF", FALSE, FALSE],
    ];
  }

  /**
   * Tests the rules.
   */
  #[DataProvider('names')]
  public function testRules(string $name, bool $syntax, bool $creation): void {
    $this->assertSame($syntax, ResourceName::syntaxError($name) === NULL);
    $this->assertSame($creation, ResourceName::creationError($name) === NULL);
  }

}
