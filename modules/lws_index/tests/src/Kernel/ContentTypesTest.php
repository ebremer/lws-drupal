<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_index\Kernel;

use Drupal\lws_index\ContentTypes;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the types read from Turtle and N-Triples content (lws10-index §4).
 */
#[Group('lws')]
#[RunTestsInSeparateProcesses]
final class ContentTypesTest extends IndexKernelTestBase {

  /**
   * Turns reading types from content on or off.
   */
  private function readContent(bool $enabled, int $maxBytes = 262144): void {
    $this->config('lws_index.settings')->set('content_types', ['enabled' => $enabled, 'max_bytes' => $maxBytes])->save();
  }

  /**
   * Creates a data resource as Alice, with content and headers.
   *
   * @param string $name
   *   Its name, as the Slug header suggests it.
   * @param string $content
   *   Its content.
   * @param array<string, string> $headers
   *   Headers, besides Slug.
   *
   * @return string
   *   Its path under the storage URI.
   */
  private function post(string $name, string $content, array $headers = ['Content-Type' => 'text/turtle']): string {
    $response = $this->as(self::ALICE, 'POST', '/lws/alice/root/', $headers + ['Slug' => $name], $content);
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    return substr((string) $response->headers->get('Location'), strlen(self::STORAGE));
  }

  /**
   * The paths of the resources of a type, as Alice finds them.
   *
   * @return list<string>
   *   Paths under the storage URI.
   */
  private function ofType(string $type, string $agent = self::ALICE): array {
    return $this->find($agent, ['type' => [self::T . $type]]);
  }

  /**
   * Tests that content is not read unless the site says so.
   */
  public function testOffByDefault(): void {
    $this->assertNull($this->container->get('lws_index.content_types')->limit());
    $this->post('note.ttl', '<> a <' . self::T . 'Alpha> .');
    $this->assertSame([], $this->ofType('Alpha'));
  }

  /**
   * Tests which statements give a resource a type, and keeping them current.
   */
  public function testTypesFromContent(): void {
    $this->readContent(TRUE);
    $turtle = '@prefix t: <' . self::T . "> .\n"
      . "<> a t:Alpha ; <http://purl.org/dc/terms/title> \"A note\"@en .\n"
      // Not the resource itself, or not an IRI: no type of it.
      . "<#it> a t:Other .\n_:b a t:Blank .\n<> a \"t:Literal\" .";
    $note = $this->post('note.ttl', $turtle, [
      'Content-Type' => 'text/turtle; charset=utf-8',
      'Link' => '<' . self::T . 'Gamma>; rel="type"',
    ]);
    $this->assertSame([$note], $this->ofType('Alpha'));
    $this->assertSame([$note], $this->ofType('Gamma'), 'Declared types stay.');
    foreach (['Other', 'Blank', 'Literal'] as $type) {
      $this->assertSame([], $this->ofType($type), $type);
    }
    $this->assertContains(self::T . 'Alpha', $this->listed($this->as(self::ALICE, 'GET', self::INDEX)), 'The type index lists it.');

    // New content states new types; its Link headers, without "Prefer:
    // set-linkset", change no declared type (LWS Core).
    $headers = ['Content-Type' => 'text/turtle', 'Link' => '<' . self::T . 'Delta>; rel="type"'];
    $response = $this->as(self::ALICE, 'PUT', '/lws/alice/' . $note, $headers, '<> a <' . self::T . 'Beta> .');
    $this->assertSame(204, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame([], $this->ofType('Alpha'));
    $this->assertSame([$note], $this->ofType('Beta'));
    $this->assertSame([$note], $this->ofType('Gamma'));
    $this->assertSame([], $this->ofType('Delta'));

    // N-Triples, which names the resource in full.
    $triples = $this->post('triples.nt', '', ['Content-Type' => 'application/n-triples']);
    $uri = self::STORAGE . $triples;
    $this->as(self::ALICE, 'PUT', '/lws/alice/' . $triples, ['Content-Type' => 'application/n-triples'], '<' . $uri . '> <' . ContentTypes::RDF_TYPE . '> <' . self::T . 'Alpha> .');
    $this->assertSame([$triples], $this->ofType('Alpha'));

    // Other media types are not read, and content that does not parse is
    // stored all the same, with no type.
    $this->post('plain.txt', '<> a <' . self::T . 'Plain> .', ['Content-Type' => 'text/plain']);
    $this->assertSame([], $this->ofType('Plain'));
    $broken = $this->post('broken.ttl', '<> a <' . self::T . 'Broken');
    $this->assertSame([], $this->ofType('Broken'));
    $this->assertSame(200, $this->as(self::ALICE, 'GET', '/lws/alice/' . $broken)->getStatusCode());

    // Deleting a resource takes its content's types too.
    $this->assertSame(204, $this->as(self::ALICE, 'DELETE', '/lws/alice/' . $triples)->getStatusCode());
    $this->assertSame([], $this->ofType('Alpha'));
  }

  /**
   * Tests that content types are searched and filtered as declared ones are.
   */
  public function testAuthorization(): void {
    $this->readContent(TRUE);
    $note = $this->post('note.ttl', '<> a <' . self::T . 'Alpha> .');
    $this->assertSame([], $this->ofType('Alpha', self::BOB));
    $this->assertNotContains(self::T . 'Alpha', $this->listed($this->as(self::BOB, 'GET', self::INDEX)));
    $this->letRead(self::BOB, [$note]);
    $this->assertSame([$note], $this->ofType('Alpha', self::BOB));
  }

  /**
   * Tests the size limit, the cap on types, and rebuilding.
   */
  public function testLimitsAndRebuild(): void {
    $this->readContent(TRUE, 64);
    $large = $this->post('large.ttl', '<> a <' . self::T . 'Alpha> .' . str_repeat(' ', 64));
    $this->assertSame([], $this->ofType('Alpha'), 'Content over the limit is not read.');

    $this->readContent(TRUE);
    $many = '';
    for ($i = 0; $i < ContentTypes::MAX_TYPES + 10; $i++) {
      $many .= '<> a <' . self::T . 'Many' . $i . "> .\n";
    }
    $this->post('many.ttl', $many);
    $this->assertCount(ContentTypes::MAX_TYPES, array_filter($this->listed($this->as(self::ALICE, 'GET', self::INDEX)), static fn (string $type): bool => str_starts_with($type, self::T . 'Many')));

    // A rebuild reads stored content with the settings of the time.
    $indexer = $this->container->get('lws_index.indexer');
    $indexer->rebuild();
    $this->assertSame([$large], $this->ofType('Alpha'));
    $this->readContent(FALSE);
    $indexer->rebuild();
    $this->assertSame([], $this->ofType('Alpha'));
  }

}
