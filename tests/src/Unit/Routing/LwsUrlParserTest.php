<?php

declare(strict_types=1);

namespace Drupal\Tests\lws\Unit\Routing;

use Drupal\lws\Routing\LwsArea;
use Drupal\lws\Routing\LwsUrlParser;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests parsing of the LWS URL space.
 */
#[CoversClass(LwsUrlParser::class)]
#[Group('lws')]
final class LwsUrlParserTest extends UnitTestCase {

  /**
   * Creates a parser for the given prefix.
   */
  private function parser(string $prefix = '/lws'): LwsUrlParser {
    return new LwsUrlParser($this->getConfigFactoryStub(['lws.settings' => ['prefix' => $prefix]]));
  }

  /**
   * Paths that address something.
   *
   * @return array<string, array{string, \Drupal\lws\Routing\LwsArea, list<string>, bool}>
   *   Raw path, area, decoded segments and whether it is a container.
   */
  public static function addressablePaths(): array {
    return [
      'storage description' => ['/lws/alice/', LwsArea::Description, [], FALSE],
      'root container' => ['/lws/alice/root/', LwsArea::Resource, ['root'], TRUE],
      'container' => ['/lws/alice/root/notes/', LwsArea::Resource, ['root', 'notes'], TRUE],
      'data resource with the same name' => ['/lws/alice/root/notes', LwsArea::Resource, ['root', 'notes'], FALSE],
      'case kept' => ['/lws/alice/root/Notes/ToDo.TXT', LwsArea::Resource, ['root', 'Notes', 'ToDo.TXT'], FALSE],
      'encoded space' => ['/lws/alice/root/To%20Do.txt', LwsArea::Resource, ['root', 'To Do.txt'], FALSE],
      'encoded unreserved' => ['/lws/alice/root/%7Euser/', LwsArea::Resource, ['root', '~user'], TRUE],
      'encoded UTF-8' => ['/lws/alice/root/caf%C3%A9/', LwsArea::Resource, ['root', 'café'], TRUE],
      'sub-delimiters' => [
        "/lws/alice/root/a+b=c;d,e!f'(g)*h&i\$j:k@l",
        LwsArea::Resource,
        ['root', "a+b=c;d,e!f'(g)*h&i\$j:k@l"],
        FALSE,
      ],
      'dots inside a name' => ['/lws/alice/root/.profile/...x', LwsArea::Resource, ['root', '.profile', '...x'], FALSE],
      'storage slug with digits and hyphens' => ['/lws/team-42/root/', LwsArea::Resource, ['root'], TRUE],
      'linkset' => ['/lws/alice/meta/0b5c3a5e-6a3e-4c1f-9d2e-3f1a2b3c4d5e', LwsArea::Meta, [], FALSE],
    ];
  }

  /**
   * Tests paths that address a description, resource or linkset.
   *
   * @param string $path
   *   The raw request path.
   * @param \Drupal\lws\Routing\LwsArea $area
   *   The expected area.
   * @param list<string> $segments
   *   The expected decoded names.
   * @param bool $container
   *   Whether the path should address a container.
   */
  #[DataProvider('addressablePaths')]
  public function testAddressable(string $path, LwsArea $area, array $segments, bool $container): void {
    $target = $this->parser()->parse($path);
    $this->assertNotNull($target);
    $this->assertSame($area, $target->area);
    $this->assertSame($path, $target->rawPath);
    $this->assertSame($segments, $target->segments);
    $this->assertSame($container, $target->container);
    $this->assertSame(str_starts_with($path, '/lws/team-42/') ? 'team-42' : 'alice', $target->storage);
  }

  /**
   * Tests that a deep path keeps every segment.
   */
  public function testDeepPath(): void {
    $names = array_map(static fn (int $i): string => 'level' . $i, range(1, 40));
    $target = $this->parser()->parse('/lws/alice/root/' . implode('/', $names) . '/');
    $this->assertNotNull($target);
    $this->assertSame(['root', ...$names], $target->segments);
    $this->assertTrue($target->container);
  }

  /**
   * Tests that the linkset UUID is extracted.
   */
  public function testMetaId(): void {
    $target = $this->parser()->parse('/lws/alice/meta/0b5c3a5e-6a3e-4c1f-9d2e-3f1a2b3c4d5e');
    $this->assertNotNull($target);
    $this->assertSame('0b5c3a5e-6a3e-4c1f-9d2e-3f1a2b3c4d5e', $target->metaId);
  }

  /**
   * Paths under the prefix at which nothing can exist.
   *
   * @return array<string, array{string}>
   *   Raw paths.
   */
  public static function unknownPaths(): array {
    return [
      'the prefix itself' => ['/lws/'],
      'storage URI without its slash' => ['/lws/alice'],
      'upper-case slug' => ['/lws/Alice/'],
      'slug starting with a hyphen' => ['/lws/-alice/'],
      'slug too long' => ['/lws/' . str_repeat('a', 64) . '/'],
      'internal path' => ['/lws/_lws/resource'],
      'root without its slash' => ['/lws/alice/root'],
      'a name that is not root' => ['/lws/alice/rooted/'],
      'unknown service' => ['/lws/alice/notifications/'],
      'linkset without an id' => ['/lws/alice/meta/'],
      'linkset with an upper-case id' => ['/lws/alice/meta/0B5C3A5E-6A3E-4C1F-9D2E-3F1A2B3C4D5E'],
      'linkset with a trailing slash' => ['/lws/alice/meta/0b5c3a5e-6a3e-4c1f-9d2e-3f1a2b3c4d5e/'],
    ];
  }

  /**
   * Tests paths under the prefix that address nothing.
   */
  #[DataProvider('unknownPaths')]
  public function testUnknown(string $path): void {
    $this->assertSame(LwsArea::Unknown, $this->parser()->parse($path)?->area);
  }

  /**
   * Paths under a storage that are not valid LWS URLs.
   *
   * @return array<string, array{string}>
   *   Raw paths.
   */
  public static function malformedPaths(): array {
    return [
      'empty segment' => ['/lws/alice/root/a//b'],
      'empty last segment' => ['/lws/alice/root//'],
      'dot-dot segment' => ['/lws/alice/root/a/../b'],
      'dot segment' => ['/lws/alice/root/./b'],
      'encoded dot-dot segment' => ['/lws/alice/root/%2e%2E/b'],
      'encoded slash' => ['/lws/alice/root/a%2Fb'],
      'bad percent-encoding' => ['/lws/alice/root/a%zz'],
      'truncated percent-encoding' => ['/lws/alice/root/a%4'],
      'raw space' => ['/lws/alice/root/a b'],
      'raw non-ASCII' => ["/lws/alice/root/caf\u{e9}"],
      'encoded NUL' => ['/lws/alice/root/a%00b'],
      'encoded control character' => ['/lws/alice/root/a%0Ab'],
      'invalid UTF-8' => ['/lws/alice/root/%FF'],
    ];
  }

  /**
   * Tests paths that are rejected with a reason.
   */
  #[DataProvider('malformedPaths')]
  public function testMalformed(string $path): void {
    $target = $this->parser()->parse($path);
    $this->assertSame(LwsArea::Malformed, $target?->area);
    $this->assertNotEmpty($target->error);
  }

  /**
   * Paths outside the LWS URL space.
   *
   * @return array<string, array{string}>
   *   Raw paths.
   */
  public static function outsidePaths(): array {
    return [
      'front page' => ['/'],
      'other route' => ['/node/1'],
      'prefix without a slash' => ['/lws'],
      'longer first segment' => ['/lwsx/alice/'],
      'token endpoint' => ['/lws/oauth/token'],
      'agent document' => ['/lws/agents/0b5c3a5e-6a3e-4c1f-9d2e-3f1a2b3c4d5e'],
    ];
  }

  /**
   * Tests that other paths are left alone.
   */
  #[DataProvider('outsidePaths')]
  public function testOutside(string $path): void {
    $this->assertNull($this->parser()->parse($path));
  }

  /**
   * Tests a configured prefix.
   */
  public function testPrefix(): void {
    $parser = $this->parser('/storage/v1');
    $this->assertSame('/storage/v1', $parser->prefix());
    $this->assertSame(LwsArea::Description, $parser->parse('/storage/v1/alice/')?->area);
    $this->assertNull($parser->parse('/lws/alice/'));
  }

}
