<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_storage\Kernel;

use Ebremer\Lws\Http\LinkHeader;
use Ebremer\Lws\Json\Json;
use Ebremer\Lws\Json\JsonPatch;
use Ebremer\Lws\Model\Linkset;
use Ebremer\Lws\Model\StorageDescription;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests linksets, user metadata and JSON Patch (LWS Core §9.1, §9.2, §9.4).
 */
#[Group('lws')]
#[RunTestsInSeparateProcesses]
final class MetadataAndPatchTest extends LwsStorageKernelTestBase {

  private const STORAGE = self::BASE . '/lws/alice/';

  private const ROOT = self::STORAGE . 'root/';

  private const LICENSE = 'https://creativecommons.org/licenses/by/4.0/';

  private const NOTE = 'https://schema.example/Note';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->storages->createStorage('alice', 'Alice', ['https://id.example/alice']);
    $this->agent = 'https://id.example/alice';
  }

  /**
   * Creates a data resource and returns its URI.
   *
   * @param string $slug
   *   The identity hint.
   * @param string $body
   *   The content.
   * @param string $type
   *   Its media type.
   * @param list<string> $links
   *   Link header values.
   */
  private function create(string $slug, string $body, string $type = 'text/plain', array $links = []): string {
    $headers = ['Content-Type' => $type, 'Slug' => $slug];
    if ($links !== []) {
      $headers['Link'] = $links;
    }
    $response = $this->send('POST', '/lws/alice/root/', $headers, $body);
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    return (string) $response->headers->get('Location');
  }

  /**
   * The path of a URI on the test site.
   */
  private function path(string $uri): string {
    return substr($uri, strlen(self::BASE));
  }

  /**
   * The targets of a relation in a response's Link headers.
   *
   * @return list<string>
   *   The targets.
   */
  private function linkTargets(Response $response, string $rel): array {
    $targets = [];
    foreach (LinkHeader::parse(array_filter($response->headers->all('link'), 'is_string')) as $link) {
      if ($link->rel === $rel) {
        $targets[] = $link->href;
      }
    }
    return $targets;
  }

  /**
   * The URI of a resource's linkset.
   */
  private function linksetUri(string $uri): string {
    return $this->linkTargets($this->send('HEAD', $this->path($uri)), 'linkset')[0];
  }

  /**
   * Reads and parses a linkset.
   */
  private function linkset(string $uri): Linkset {
    $response = $this->send('GET', $this->path($this->linksetUri($uri)));
    $this->assertSame(200, $response->getStatusCode());
    return Linkset::parse($this->json($response));
  }

  /**
   * Sends a JSON Patch.
   *
   * @param string $uri
   *   The resource or linkset.
   * @param \Ebremer\Lws\Json\JsonPatch|string $patch
   *   The patch.
   * @param array<string, string> $headers
   *   Further headers.
   */
  private function patch(string $uri, JsonPatch|string $patch, array $headers = []): Response {
    return $this->send('PATCH', $this->path($uri), $headers + ['Content-Type' => 'application/json-patch+json'], (string) $patch);
  }

  /**
   * Tests the metadata a create sets with Link headers (§9.2).
   */
  public function testInitialMetadata(): void {
    $uri = $this->create('note.txt', 'x', 'text/plain', [
      '<' . self::NOTE . '>; rel="type"',
      '<' . self::LICENSE . '>; rel="license"; title="CC BY"',
      // Server-managed relations, LWS classes and other contexts are ignored.
      '<https://linkset.invalid/forged-parent/>; rel="up"',
      '<https://linkset.invalid/forged>; rel="linkset"',
      '<https://www.w3.org/ns/lws#Container>; rel="type"; anchor="https://elsewhere.example/"',
      '<https://elsewhere.example/about>; rel="describedby"; anchor="https://elsewhere.example/"',
    ]);
    $read = $this->send('GET', $this->path($uri));
    $this->assertSame(['https://www.w3.org/ns/lws#DataResource', self::NOTE], $this->linkTargets($read, 'type'));
    $this->assertSame([self::ROOT], $this->linkTargets($read, 'up'));

    $linkset = $this->linkset($uri);
    $this->assertSame([self::ROOT], $linkset->hrefs('up', $uri));
    $this->assertSame(['https://www.w3.org/ns/lws#DataResource', self::NOTE], $linkset->hrefs('type', $uri));
    $this->assertSame([self::LICENSE], $linkset->hrefs('license', $uri));
    $this->assertSame([], $linkset->hrefs('describedby'));
    $document = $this->json($this->send('GET', $this->path($this->linksetUri($uri))));
    $this->assertSame('CC BY', $document['linkset'][0]['license'][0]['title']);

    // Listings name the member's types.
    $items = $this->json($this->send('GET', '/lws/alice/root/'))['items'];
    $this->assertSame(['DataResource', self::NOTE], $items[0]['type']);
  }

  /**
   * Tests patching a linkset (§9.1).
   */
  public function testPatchLinkset(): void {
    $uri = $this->create('l.txt', 'x');
    $linkset = $this->linksetUri($uri);
    $read = $this->send('GET', $this->path($linkset));
    $this->assertMatchesRegularExpression('/\bPATCH\b/', (string) $read->headers->get('Allow'));
    $this->assertMatchesRegularExpression('/\bPUT\b/', (string) $read->headers->get('Allow'));
    $this->assertSame('application/json-patch+json', $read->headers->get('Accept-Patch'));
    $etag = (string) $read->getEtag();

    $patch = (new JsonPatch())->add('/linkset/0/license', [['href' => self::LICENSE]])->add('/linkset/0/type/-', ['href' => self::NOTE]);
    $this->assertProblem($this->patch($linkset, $patch, ['If-Match' => '"stale"']), 412, $linkset);
    $response = $this->patch($linkset, $patch, ['If-Match' => $etag]);
    $this->assertSame(204, $response->getStatusCode(), (string) $response->getContent());
    $this->assertNotSame($etag, $response->getEtag());
    $this->assertSame($response->getEtag(), $this->send('GET', $this->path($linkset))->getEtag());
    $this->assertSame([self::LICENSE], $this->linkset($uri)->hrefs('license', $uri));
    $this->assertSame(['DataResource', self::NOTE], $this->json($this->send('GET', '/lws/alice/root/'))['items'][0]['type']);

    // Remove what was added.
    $this->assertSame(204, $this->patch($linkset, (new JsonPatch())->remove('/linkset/0/license'))->getStatusCode());
    $this->assertSame([], $this->linkset($uri)->hrefs('license', $uri));
  }

  /**
   * Tests the linkset patches that are refused.
   */
  public function testRefusedLinksetPatches(): void {
    $uri = $this->create('r.txt', 'x');
    $linkset = $this->linksetUri($uri);
    $before = $this->send('GET', $this->path($linkset))->getEtag();

    // Server-managed relations cannot change.
    $this->assertProblem($this->patch($linkset, (new JsonPatch())->replace('/linkset/0/up', [['href' => 'https://linkset.invalid/forged-parent/']])), 409, $linkset);
    $this->assertProblem($this->patch($linkset, (new JsonPatch())->remove('/linkset/0/up')), 409, $linkset);
    $this->assertProblem($this->patch($linkset, (new JsonPatch())->remove('/linkset/0/type/0')), 409, $linkset);
    $this->assertProblem($this->patch($linkset, (new JsonPatch())->add('/linkset/0/type/-', ['href' => 'https://www.w3.org/ns/lws#Container'])), 409, $linkset);
    $this->assertProblem($this->patch($linkset, (new JsonPatch())->add('/linkset/0/https:~1~1www.w3.org~1ns~1lws#storage', [['href' => 'https://x.example/']])), 409, $linkset);
    // The result must still be a linkset of the resource.
    $this->assertProblem($this->patch($linkset, (new JsonPatch())->replace('/linkset', 'not a linkset')), 422, $linkset);
    $this->assertProblem($this->patch($linkset, (new JsonPatch())->replace('/linkset/0/anchor', 'https://elsewhere.example/')), 422, $linkset);
    $this->assertProblem($this->patch($linkset, (new JsonPatch())->add('/linkset/0/license', 'not targets')), 422, $linkset);
    $this->assertProblem($this->patch($linkset, (new JsonPatch())->add('/linkset/0/license', [['title' => 'no href']])), 422, $linkset);
    // A failed test, a missing location, a malformed patch, another format.
    $this->assertProblem($this->patch($linkset, (new JsonPatch())->test('/linkset/0/anchor', 'https://elsewhere.example/')), 409, $linkset);
    $this->assertProblem($this->patch($linkset, (new JsonPatch())->remove('/linkset/0/license')), 409, $linkset);
    $this->assertProblem($this->patch($linkset, '{"op": "add"}'), 400, $linkset);
    $response = $this->send('PATCH', $this->path($linkset), ['Content-Type' => 'application/merge-patch+json'], '{}');
    $this->assertProblem($response, 415, $linkset);
    $this->assertSame('application/json-patch+json', $response->headers->get('Accept-Patch'));

    // Nothing changed, and up still names the real parent.
    $this->assertSame($before, $this->send('GET', $this->path($linkset))->getEtag());
    $this->assertSame([self::ROOT], $this->linkTargets($this->send('GET', $this->path($uri)), 'up'));
  }

  /**
   * Tests replacing a linkset.
   */
  public function testPutLinkset(): void {
    $uri = $this->create('p.txt', 'x', 'text/plain', ['<' . self::LICENSE . '>; rel="license"']);
    $linkset = $this->linksetUri($uri);
    $document = ['linkset' => [['anchor' => $uri, 'describedby' => [['href' => 'https://schema.example/note']]]]];
    $response = $this->send('PUT', $this->path($linkset), ['Content-Type' => 'application/linkset+json'], json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $this->assertSame(204, $response->getStatusCode(), (string) $response->getContent());
    $parsed = $this->linkset($uri);
    // The server-managed relations stay; the license is replaced.
    $this->assertSame([self::ROOT], $parsed->hrefs('up', $uri));
    $this->assertSame(['https://www.w3.org/ns/lws#DataResource'], $parsed->hrefs('type', $uri));
    $this->assertSame([], $parsed->hrefs('license', $uri));
    $this->assertSame(['https://schema.example/note'], $parsed->hrefs('describedby', $uri));

    // What a GET returned can be put back as it is.
    $current = (string) $this->send('GET', $this->path($linkset))->getContent();
    $this->assertSame(204, $this->send('PUT', $this->path($linkset), ['Content-Type' => 'application/linkset+json'], $current)->getStatusCode());

    $forged = ['linkset' => [['anchor' => $uri, 'up' => [['href' => 'https://linkset.invalid/']]]]];
    $this->assertProblem($this->send('PUT', $this->path($linkset), ['Content-Type' => 'application/linkset+json'], (string) json_encode($forged)), 409, $linkset);
    $elsewhere = ['linkset' => [['anchor' => 'https://elsewhere.example/']]];
    $this->assertProblem($this->send('PUT', $this->path($linkset), ['Content-Type' => 'application/linkset+json'], (string) json_encode($elsewhere)), 422, $linkset);
    $this->assertProblem($this->send('PUT', $this->path($linkset), ['Content-Type' => 'application/linkset+json'], 'not JSON'), 400, $linkset);
    $this->assertProblem($this->send('PUT', $this->path($linkset), ['Content-Type' => 'text/plain'], '{}'), 415, $linkset);
  }

  /**
   * Tests patching JSON data resources, as Touchstone's baseline does.
   */
  public function testPatchJson(): void {
    $uri = $this->create('doc.json', '{"title":"first","keep":true,"drop":"gone","tags":["a","b"]}', 'application/json');
    $read = $this->send('GET', $this->path($uri));
    $this->assertSame('application/json-patch+json', $read->headers->get('Accept-Patch'));
    $patch = (new JsonPatch())->test('/title', 'first')->replace('/title', 'second')->add('/added', 42)->remove('/drop')->add('/tags/-', 'c');
    $response = $this->patch($uri, $patch, ['If-Match' => (string) $read->getEtag()]);
    $this->assertSame(204, $response->getStatusCode(), (string) $response->getContent());
    $after = $this->send('GET', $this->path($uri));
    $this->assertSame($response->getEtag(), $after->getEtag());
    $this->assertSame(['title' => 'second', 'keep' => TRUE, 'tags' => ['a', 'b', 'c'], 'added' => 42], json_decode($this->body($after), TRUE));
    $this->assertSame('application/json', $after->headers->get('Content-Type'));

    // All or nothing.
    $failing = (new JsonPatch())->replace('/title', 'third')->test('/keep', FALSE);
    $this->assertProblem($this->patch($uri, $failing), 409, $uri);
    $this->assertSame('second', json_decode($this->body($this->send('GET', $this->path($uri))), TRUE)['title']);
    // A stale entity tag.
    $this->assertProblem($this->patch($uri, (new JsonPatch())->add('/x', 1), ['If-Match' => (string) $read->getEtag()]), 412, $uri);
    // A +json type is JSON too.
    $ld = $this->create('doc.jsonld', '{"@id":"#a"}', 'application/ld+json');
    $this->assertSame(204, $this->patch($ld, (new JsonPatch())->add('/name', 'A'))->getStatusCode());
  }

  /**
   * Tests the patches of data resources that are refused.
   */
  public function testRefusedPatches(): void {
    $text = $this->create('t.txt', 'plain text');
    $this->assertNull($this->send('GET', $this->path($text))->headers->get('Accept-Patch'));
    $this->assertProblem($this->patch($text, (new JsonPatch())->add('/a', 1)), 415, $text);
    $broken = $this->create('broken.json', '{not json', 'application/json');
    $this->assertProblem($this->patch($broken, (new JsonPatch())->add('/a', 1)), 422, $broken);
    $json = $this->create('j.json', '{}', 'application/json');
    $this->assertProblem($this->patch($json, '[{"op":"jump","path":"/a"}]'), 400, $json);
    $this->assertProblem($this->patch($json, 'not JSON'), 400, $json);
    $response = $this->send('PATCH', $this->path($json), ['Content-Type' => 'application/merge-patch+json'], '{"a":1}');
    $this->assertProblem($response, 415, $json);
    $this->assertSame('application/json-patch+json', $response->headers->get('Accept-Patch'));
    $this->assertProblem($this->patch(self::ROOT . 'missing.json', (new JsonPatch())->add('/a', 1)), 404, self::ROOT . 'missing.json');
    $this->assertSame('{}', $this->body($this->send('GET', $this->path($json))));
  }

  /**
   * Tests updating content and links together (§9.4, Prefer: set-linkset).
   */
  public function testSetLinkset(): void {
    $uri = $this->create('s.json', '{"a":1}', 'application/json', ['<' . self::LICENSE . '>; rel="license"']);
    // PUT replaces the links.
    $response = $this->send('PUT', $this->path($uri), [
      'Content-Type' => 'application/json',
      'Prefer' => 'set-linkset',
      'Link' => '<https://schema.example/s>; rel="describedby"',
    ], '{"a":2}');
    $this->assertSame(204, $response->getStatusCode());
    $this->assertSame('set-linkset', $response->headers->get('Preference-Applied'));
    $linkset = $this->linkset($uri);
    $this->assertSame([], $linkset->hrefs('license', $uri));
    $this->assertSame(['https://schema.example/s'], $linkset->hrefs('describedby', $uri));

    // PATCH adds to them.
    $response = $this->send('PATCH', $this->path($uri), [
      'Content-Type' => 'application/json-patch+json',
      'Prefer' => 'return=minimal, set-linkset',
      'Link' => '<' . self::LICENSE . '>; rel="license"',
    ], (string) (new JsonPatch())->replace('/a', 3));
    $this->assertSame(204, $response->getStatusCode());
    $this->assertSame('set-linkset', $response->headers->get('Preference-Applied'));
    $linkset = $this->linkset($uri);
    $this->assertSame([self::LICENSE], $linkset->hrefs('license', $uri));
    $this->assertSame(['https://schema.example/s'], $linkset->hrefs('describedby', $uri));

    // Without the preference, Link headers on an update change nothing.
    $headers = ['Content-Type' => 'application/json', 'Link' => '<https://x.example/>; rel="author"'];
    $response = $this->send('PUT', $this->path($uri), $headers, '{"a":4}');
    $this->assertNull($response->headers->get('Preference-Applied'));
    $this->assertSame([], $this->linkset($uri)->hrefs('author', $uri));
  }

  /**
   * Tests that the storage description advertises patch support.
   */
  public function testPatchSupportCapability(): void {
    $description = $this->json($this->send('GET', '/lws/alice/'));
    $this->assertSame('https://www.w3.org/ns/lws#PatchSupport', $description['capability'][0]['type']);
    $this->assertSame(['application/json-patch+json'], $description['capability'][0]['format']['application/json']);
    $this->assertSame(self::STORAGE, StorageDescription::parse($description, self::STORAGE)->id);
  }

  /**
   * Tests the PHP LWS client's JSON Patch cases through PATCH.
   *
   * Each case's document is a JSON resource; the patch must give the
   * expected document, or fail with 409 and change nothing.
   */
  public function testJsonPatchFixtures(): void {
    $fixtures = dirname((string) (new \ReflectionClass(JsonPatch::class))->getFileName(), 4) . '/conformance/fixtures/json-patch-apply.json';
    if (!is_file($fixtures)) {
      $this->markTestSkipped('This version of ebremer/lws-client has no JSON Patch apply fixtures.');
    }
    $cases = Json::members(Json::decode((string) file_get_contents($fixtures)))['cases'] ?? [];
    $this->assertNotEmpty($cases);
    foreach ($cases as $i => $case) {
      $case = Json::members($case) ?? [];
      $name = (string) $case['name'];
      $before = Json::encode($case['doc']);
      $uri = $this->create('case-' . $i . '.json', $before, 'application/json');
      $response = $this->patch($uri, Json::encode($case['patch']));
      $after = Json::decode($this->body($this->send('GET', $this->path($uri))));
      if (($case['error'] ?? FALSE) === TRUE) {
        $this->assertSame(409, $response->getStatusCode(), $name);
        $this->assertSame($before, Json::encode($after), $name);
      }
      else {
        $this->assertSame(204, $response->getStatusCode(), $name . ': ' . $response->getContent());
        $this->assertSame(self::canonical($case['expected']), self::canonical($after), $name);
      }
    }
  }

  /**
   * A JSON value with object members sorted, so member order does not matter.
   */
  private static function canonical(mixed $value): string {
    $sort = static function (mixed $value) use (&$sort): mixed {
      if (Json::isList($value)) {
        return array_map($sort, $value);
      }
      $members = Json::members($value);
      if ($members === NULL) {
        return $value;
      }
      ksort($members, SORT_STRING);
      return Json::object(array_map($sort, $members));
    };
    return Json::encode($sort($value));
  }

}
