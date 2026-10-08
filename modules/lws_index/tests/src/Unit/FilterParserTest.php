<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_index\Unit;

use Drupal\lws\Http\LwsHttpException;
use Drupal\lws_index\Indexer;
use Drupal\lws_index\Query\FilterParser;
use Drupal\lws_index\Query\TypeFilter;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the parser of application/lws-query+json filters.
 */
#[Group('lws')]
final class FilterParserTest extends UnitTestCase {

  private const A = 'https://types.example/#A';

  private const B = 'https://types.example/#B';

  /**
   * Tests that filters become groups in conjunctive normal form.
   */
  public function testGroups(): void {
    $filter = FilterParser::parse(json_encode([
      '@context' => 'https://www.w3.org/ns/lws/v1',
      'type' => [self::B, [self::A, self::B], self::B, [self::B, self::A]],
      'DescribedBy' => ['urn:shape:1'],
      'https://rels.example/X' => [],
    ], JSON_THROW_ON_ERROR));
    $this->assertSame([
      ['rel' => 'describedby', 'hrefs' => ['urn:shape:1']],
      ['rel' => 'type', 'hrefs' => [self::A, self::B]],
      ['rel' => 'type', 'hrefs' => [self::B]],
    ], $filter->groups);
    $this->assertSame(['describedby', 'type'], $filter->relations());
    $this->assertSame([
      [Indexer::hash('describedby', 'urn:shape:1')],
      [Indexer::hash('type', self::A), Indexer::hash('type', self::B)],
      [Indexer::hash('type', self::B)],
    ], $filter->hashes());
    $this->assertEquals($filter, TypeFilter::fromArray($filter->toArray()));

    // Equal filters are equal, however written.
    $this->assertEquals($filter, FilterParser::parse(json_encode([
      'describedby' => ['urn:shape:1', 'urn:shape:1'],
      'type' => [[self::B, self::A], self::B],
    ], JSON_THROW_ON_ERROR)));

    $this->assertTrue(FilterParser::parse('{}')->isEmpty());
    $this->assertTrue(FilterParser::parse('{"type": [], "@type": "x"}')->isEmpty());
    $this->assertNull(TypeFilter::fromArray([['type', []]]));
    $this->assertNull(TypeFilter::fromArray(['type' => 'x']));
  }

  /**
   * Values that are absolute IRIs, or not.
   *
   * @return array<string, array{string, bool}>
   *   The value, and whether it is one.
   */
  public static function iris(): array {
    return [
      'https' => ['https://schema.org/Person', TRUE],
      'fragment' => ['https://types.example/#A', TRUE],
      'query' => ['https://types.example/?a=b&c', TRUE],
      'urn' => ['urn:touchstone:x', TRUE],
      'did' => ['did:key:z6MkhaXgBZDvotDkL5257faiztiGiC2QtKLGpbnnEGta2doK', TRUE],
      'percent-encoding' => ['https://types.example/caf%C3%A9', TRUE],
      'non-ASCII' => ['https://types.example/café', TRUE],
      'IP literal' => ['http://[::1]/x', TRUE],
      'empty path' => ['urn:', TRUE],
      'no scheme' => ['Person', FALSE],
      'relative' => ['/types/Person', FALSE],
      'space' => ['not an iri', FALSE],
      'space in path' => ['https://types.example/a b', FALSE],
      'two fragments' => ['https://types.example/#a#b', FALSE],
      'angle bracket' => ['https://types.example/<a>', FALSE],
      'bad percent-encoding' => ['https://types.example/%zz', FALSE],
      'scheme starting with a digit' => ['1http://types.example/', FALSE],
      'control character' => ["https://types.example/\x01", FALSE],
      'empty' => ['', FALSE],
    ];
  }

  /**
   * Tests which values are IRIs.
   */
  #[DataProvider('iris')]
  public function testIris(string $value, bool $iri): void {
    $this->assertSame($iri, FilterParser::isIri($value));
  }

  /**
   * Bodies that are not filters.
   *
   * @return array<string, array{string}>
   *   The body.
   */
  public static function invalidFilters(): array {
    return [
      'not JSON' => ['{"type": ["urn:a"'],
      'invalid UTF-8' => ["{\"type\": [\"urn:\xff\"]}"],
      'an array' => ['["urn:a"]'],
      'a string' => ['"urn:a"'],
      'type not an array' => ['{"type": "urn:a"}'],
      'type an object' => ['{"type": {"0": "urn:a"}}'],
      'relation not an array' => ['{"describedby": "urn:a"}'],
      'empty group' => ['{"type": [[]]}'],
      'number' => ['{"type": [42]}'],
      'number in a group' => ['{"type": [["urn:a", 7]]}'],
      'object' => ['{"type": [{"not": "urn:a"}]}'],
      'nested group' => ['{"type": [[["urn:a"]]]}'],
      'null' => ['{"type": [null]}'],
      'relative IRI' => ['{"type": ["Person"]}'],
      'relative relation target' => ['{"describedby": ["not an iri"]}'],
      'relative IRI in a group' => ['{"type": [["urn:a", "b"]]}'],
    ];
  }

  /**
   * Tests that bodies that are not filters are refused with 400.
   */
  #[DataProvider('invalidFilters')]
  public function testInvalid(string $body): void {
    try {
      FilterParser::parse($body);
      $this->fail('The filter was accepted.');
    }
    catch (LwsHttpException $e) {
      $this->assertSame(400, $e->getStatusCode());
    }
  }

  /**
   * Tests that filters beyond the limits are refused with 422.
   */
  public function testComplexity(): void {
    $iris = static fn (int $count): array => array_map(static fn (int $i): string => 'urn:t:' . $i, range(1, $count));
    $ok = [
      ['type' => $iris(FilterParser::MAX_GROUPS)],
      ['type' => [$iris(FilterParser::MAX_IRIS)]],
      // Duplicates do not count.
      ['type' => array_fill(0, FilterParser::MAX_GROUPS + 5, 'urn:t:1')],
    ];
    foreach ($ok as $filter) {
      FilterParser::parse(json_encode($filter, JSON_THROW_ON_ERROR));
    }
    $tooComplex = [
      ['type' => $iris(FilterParser::MAX_GROUPS + 1)],
      ['type' => [$iris(FilterParser::MAX_IRIS + 1)]],
      ['type' => array_slice($iris(20), 0, 16), 'describedby' => array_slice($iris(40), 20)],
      ['type' => ['urn:' . str_repeat('x', FilterParser::MAX_BYTES)]],
    ];
    foreach ($tooComplex as $filter) {
      try {
        FilterParser::parse(json_encode($filter, JSON_THROW_ON_ERROR));
        $this->fail('The filter was accepted.');
      }
      catch (LwsHttpException $e) {
        $this->assertSame(422, $e->getStatusCode());
      }
    }
  }

}
