<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_index\Kernel;

use Drupal\lws\Access\AccessDecisionInterface;
use Drupal\lws\Agent\RequestingAgent;
use Drupal\lws_authz\Policy\AccessPolicy;
use Drupal\lws_index\Controller\IndexController;
use Drupal\lws_index\Query\FilterParser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the type search service.
 */
#[Group('lws')]
#[RunTestsInSeparateProcesses]
final class TypeSearchTest extends IndexKernelTestBase {

  /**
   * An extension relation.
   */
  private const REVIEWED_BY = 'https://rels.example/reviewedBy';

  /**
   * Tests a search and the shape of its answer.
   */
  public function testSearch(): void {
    $one = $this->create('root/', 'one.txt', ['Person', 'Agent']);
    $this->create('root/', 'two.txt', ['Event']);
    $folder = $this->createContainer('root/', 'people', ['Person']);

    $response = $this->search(self::ALICE, ['type' => [self::T . 'Person']]);
    $this->assertSame([$one, $folder], $this->found($response));
    $this->assertSame('application/lws+json', $response->headers->get('Content-Type'));
    $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    $this->assertSame(['Accept', 'Authorization'], array_map('trim', explode(',', (string) $response->headers->get('Vary'))));
    $this->assertFalse($response->headers->has('Content-Location'));
    $this->assertFalse($response->headers->has('ETag'));
    $page = $this->json($response);
    $this->assertSame('https://www.w3.org/ns/lws/v1', $page['@context']);
    $this->assertArrayNotHasKey('id', $page);
    $this->assertSame(2, $page['totalItems']);
    $this->assertIsArray($page['items']);
    $this->assertSame(['DataResource', self::T . 'Person', self::T . 'Agent'], $page['items'][0]['type']);
    $this->assertSame(self::STORAGE . $one, $page['items'][0]['id']);
    $this->assertSame('text/plain', $page['items'][0]['format']);
    $this->assertSame(7, $page['items'][0]['size']);
    $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $page['items'][0]['modified']);
    $this->assertSame(['Container', self::T . 'Person'], $page['items'][1]['type']);
    $this->assertArrayNotHasKey('format', $page['items'][1]);
    $first = $this->link($response, 'first');
    $this->assertNotNull($first);
    $this->assertStringStartsWith(self::STORAGE . 'types/search?page=', $first);
    $this->assertNull($this->link($response, 'next'));

    // The same filter makes the same first page link.
    $again = $this->search(self::ALICE, ['type' => [[self::T . 'Person'], self::T . 'Person']]);
    $this->assertSame($first, $this->link($again, 'first'));

    // Other formats are negotiated as for container listings.
    $ld = $this->search(self::ALICE, ['type' => [self::T . 'Person']], ['Accept' => 'application/ld+json']);
    $this->assertSame(200, $ld->getStatusCode());
    $this->assertStringStartsWith('application/ld+json', (string) $ld->headers->get('Content-Type'));
    $low = $this->search(self::ALICE, ['type' => [self::T . 'Person']], ['Accept' => 'image/png, application/lws+json;q=0.1']);
    $this->assertSame('application/lws+json', $low->headers->get('Content-Type'));
    $refused = $this->search(self::ALICE, ['type' => [self::T . 'Person']], ['Accept' => 'image/png']);
    $this->assertSame(406, $refused->getStatusCode());

    // A search is safe: nothing changes.
    $etag = $this->as(self::ALICE, 'HEAD', '/lws/alice/' . $one)->headers->get('ETag');
    $root = $this->as(self::ALICE, 'HEAD', '/lws/alice/root/')->headers->get('ETag');
    $this->search(self::ALICE, ['type' => [self::T . 'Person']]);
    $this->assertSame($etag, $this->as(self::ALICE, 'HEAD', '/lws/alice/' . $one)->headers->get('ETag'));
    $this->assertSame($root, $this->as(self::ALICE, 'HEAD', '/lws/alice/root/')->headers->get('ETag'));
  }

  /**
   * Tests conjunctive normal form, and the native classes as types.
   */
  public function testConjunctiveNormalForm(): void {
    $ab = $this->create('root/', 'ab', ['Alpha', 'Beta']);
    $a = $this->create('root/', 'a', ['Alpha']);
    $g = $this->create('root/', 'g', ['Gamma']);
    $folder = $this->createContainer('root/', 'folder', ['Alpha']);

    [$alpha, $beta, $gamma] = [self::T . 'Alpha', self::T . 'Beta', self::T . 'Gamma'];
    $this->assertSame([$ab, $a, $g, $folder], $this->find(self::ALICE, ['type' => [[$alpha, $gamma]]]));
    $this->assertSame([$ab], $this->find(self::ALICE, ['type' => [$alpha, $beta]]));
    $this->assertSame([$ab], $this->find(self::ALICE, ['type' => [[$beta, $gamma], $alpha]]));
    $this->assertSame([$ab, $a], $this->find(self::ALICE, ['type' => [$alpha, self::LWS_DATA]]));
    $this->assertSame([$folder], $this->find(self::ALICE, ['type' => [$alpha, self::LWS_CONTAINER]]));
    $this->assertSame(['root/', $folder], $this->find(self::ALICE, ['type' => [self::LWS_CONTAINER]]));
    $this->assertSame([], $this->find(self::ALICE, ['type' => [self::T . 'Nothing']]));

    // No constraint matches everything; so does a key with an empty array,
    // and @ members are no constraint.
    $all = ['root/', $ab, $a, $g, $folder];
    $this->assertSame($all, $this->find(self::ALICE, []));
    $this->assertSame($all, $this->find(self::ALICE, ['type' => [], 'describedby' => []]));
    $this->assertSame([$ab, $a, $folder], $this->find(self::ALICE, [
      '@context' => 'https://www.w3.org/ns/lws/v1',
      '@type' => 'urn:example:not-a-filter',
      'type' => [self::T . 'Alpha'],
    ]));
  }

  /**
   * Tests filtering on relations other than type.
   */
  public function testRelations(): void {
    [$one, $two, $cc0] = ['https://shapes.example/one', 'https://shapes.example/two', 'https://licenses.example/cc0'];
    $shaped = $this->create('root/', 'shaped', ['Alpha'], ['describedby' => $one, 'license' => $cc0]);
    $other = $this->create('root/', 'other', ['Alpha'], [
      'describedby' => $two,
      'self' => 'https://elsewhere.example/x',
      self::REVIEWED_BY => 'https://people.example/carol',
    ]);

    $this->assertSame([$shaped], $this->find(self::ALICE, ['describedby' => [$one]]));
    $this->assertSame([$shaped, $other], $this->find(self::ALICE, ['describedby' => [[$one, $two]]]));
    $this->assertSame([], $this->find(self::ALICE, ['describedby' => [$one, $two]]));
    $this->assertSame([$shaped], $this->find(self::ALICE, ['type' => [self::T . 'Alpha'], 'license' => [$cc0]]));
    // Registered relation types compare case-insensitively.
    $this->assertSame([$shaped], $this->find(self::ALICE, ['DescribedBy' => [$one]]));

    // A relation the site does not let searches filter on matches nothing,
    // as a target nothing declares does; structural relations never match.
    $this->assertSame([], $this->find(self::ALICE, ['self' => ['https://elsewhere.example/x']]));
    $this->assertSame([], $this->find(self::ALICE, [self::REVIEWED_BY => ['https://people.example/carol']]));
    $this->assertSame([], $this->find(self::ALICE, ['urn:example:unindexed' => ['urn:example:x']]));
    $this->assertSame([], $this->find(self::ALICE, ['type' => [self::T . 'Alpha'], 'up' => [self::STORAGE . 'root/']]));
    $this->assertSame([], $this->find(self::ALICE, ['describedby' => ['https://shapes.example/three']]));

    // Every relation is indexed, so one the site adds applies at once; a
    // structural one never does.
    $this->config('lws_index.settings')->set('relations', ['self', 'up', 'describedby', self::REVIEWED_BY])->save();
    $this->assertSame([$other], $this->find(self::ALICE, [self::REVIEWED_BY => ['https://people.example/carol']]));
    $this->assertSame([], $this->find(self::ALICE, ['self' => ['https://elsewhere.example/x']]));
    $this->assertSame([], $this->find(self::ALICE, ['up' => [self::STORAGE . 'root/']]));
    $this->assertSame([], $this->find(self::ALICE, ['license' => [$cc0]]));
  }

  /**
   * Tests that the index follows the resources' types and links.
   */
  public function testFollowsChanges(): void {
    $one = $this->create('root/', 'one', ['Alpha']);
    $this->assertSame([$one], $this->find(self::ALICE, ['type' => [self::T . 'Alpha']]));

    // A PUT does not change the types without "Prefer: set-linkset".
    $headers = ['Content-Type' => 'text/plain', 'Link' => self::links(['Beta'], [])];
    $put = $this->as(self::ALICE, 'PUT', '/lws/alice/' . $one, $headers, 'new');
    $this->assertSame(204, $put->getStatusCode(), (string) $put->getContent());
    $this->assertSame([$one], $this->find(self::ALICE, ['type' => [self::T . 'Alpha']]));
    $put = $this->as(self::ALICE, 'PUT', '/lws/alice/' . $one, [
      'Content-Type' => 'text/plain',
      'Prefer' => 'set-linkset',
      'Link' => self::links(['Beta'], ['describedby' => 'https://shapes.example/one']),
    ], 'newer');
    $this->assertSame(204, $put->getStatusCode(), (string) $put->getContent());
    $this->assertSame([], $this->find(self::ALICE, ['type' => [self::T . 'Alpha']]));
    $shape = ['describedby' => ['https://shapes.example/one']];
    $this->assertSame([$one], $this->find(self::ALICE, ['type' => [self::T . 'Beta']] + $shape));

    // As does a change to the linkset.
    $linkset = $this->link($this->as(self::ALICE, 'HEAD', '/lws/alice/' . $one), 'linkset');
    $this->assertNotNull($linkset);
    $patch = $this->as(self::ALICE, 'PATCH', substr($linkset, strlen(self::BASE)), ['Content-Type' => 'application/json-patch+json'], json_encode([
      ['op' => 'add', 'path' => '/linkset/0/describedby/-', 'value' => ['href' => 'https://shapes.example/two']],
    ], JSON_THROW_ON_ERROR));
    $this->assertSame(204, $patch->getStatusCode(), (string) $patch->getContent());
    $this->assertSame([$one], $this->find(self::ALICE, ['describedby' => ['https://shapes.example/two']]));

    // A deleted resource is found no more.
    $this->assertSame(204, $this->as(self::ALICE, 'DELETE', '/lws/alice/' . $one)->getStatusCode());
    $this->assertSame([], $this->find(self::ALICE, ['type' => [self::T . 'Beta']]));
  }

  /**
   * Tests requests that are not searches.
   */
  public function testErrors(): void {
    $error = function (int $status, array|string $filter, array $headers = []): void {
      $response = $this->search(self::ALICE, $filter, $headers);
      $this->assertSame($status, $response->getStatusCode(), (string) $response->getContent());
      $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));
    };
    $alpha = self::T . 'Alpha';
    $error(400, '{"type": ["urn:a"');
    $error(400, '["urn:a"]');
    $error(400, ['type' => $alpha]);
    $error(400, ['describedby' => ['not an iri']]);
    $error(400, ['type' => ['Person']]);
    $error(400, ['type' => [[]]]);
    $error(400, ['type' => [42]]);
    $error(400, ['type' => [[$alpha, 7]]]);
    $error(400, ['type' => [['not' => $alpha]]]);
    $error(400, ['type' => [['https://a.example/#x#y']]]);

    // RFC 10008: a QUERY says the format of its query.
    $response = $this->as(self::ALICE, 'QUERY', self::SEARCH, [], '{}');
    $this->assertSame(400, $response->getStatusCode());
    $response = $this->search(self::ALICE, '{}', ['Content-Type' => 'application/sparql-query']);
    $this->assertSame(415, $response->getStatusCode());
    $this->assertSame('application/lws-query+json', $response->headers->get('Accept-Query'));
    $this->assertSame(200, $this->search(self::ALICE, '{}', ['Content-Type' => 'application/LWS-query+json; charset=utf-8'])->getStatusCode());

    // Too large, and too complex: refused, never narrowed.
    $error(413, str_repeat(' ', IndexController::MAX_BYTES + 1) . '{}');
    $groups = array_map(static fn (int $i): string => self::T . 'None' . $i, range(1, FilterParser::MAX_GROUPS + 1));
    $error(422, ['type' => $groups]);
    $error(422, ['type' => [array_map(static fn (int $i): string => self::T . 'None' . $i, range(1, FilterParser::MAX_IRIS + 1))]]);
    $error(422, ['type' => [self::T . str_repeat('x', FilterParser::MAX_BYTES)]]);
    $this->assertSame(200, $this->search(self::ALICE, ['type' => array_slice($groups, 0, FilterParser::MAX_GROUPS)])->getStatusCode());

    // Page links are read with GET; the search itself is not.
    $response = $this->as(self::ALICE, 'GET', self::SEARCH);
    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame(404, $this->as(self::ALICE, 'GET', self::SEARCH . '?page=nonsense')->getStatusCode());
    $this->assertSame(405, $this->as(self::ALICE, 'QUERY', self::INDEX, ['Content-Type' => 'application/lws-query+json'], '{}')->getStatusCode());
    $this->assertSame(404, $this->as(self::ALICE, 'QUERY', '/lws/nobody/types/search', ['Content-Type' => 'application/lws-query+json'], '{}')->getStatusCode());
  }

  /**
   * Tests OPTIONS, and that the storage description advertises the services.
   */
  public function testDiscovery(): void {
    $options = $this->send('OPTIONS', self::SEARCH);
    $this->assertSame(204, $options->getStatusCode());
    $this->assertSame('GET, HEAD, QUERY, OPTIONS', $options->headers->get('Allow'));
    $this->assertSame('application/lws-query+json', $options->headers->get('Accept-Query'));
    $preflight = $this->send('OPTIONS', self::SEARCH, [
      'Origin' => 'https://app.example',
      'Access-Control-Request-Method' => 'QUERY',
    ]);
    $this->assertStringContainsString('QUERY', (string) $preflight->headers->get('Access-Control-Allow-Methods'));
    $options = $this->send('OPTIONS', self::INDEX);
    $this->assertSame('GET, HEAD, OPTIONS', $options->headers->get('Allow'));
    $this->assertFalse($options->headers->has('Accept-Query'));

    $description = $this->json($this->send('GET', '/lws/alice/'));
    $services = array_column($description['service'], 'serviceEndpoint', 'type');
    $this->assertSame(self::STORAGE . 'types/index', $services['TypeIndexService']);
    $this->assertSame(self::STORAGE . 'types/search', $services['TypeSearchService']);
    // Nothing tells which relations a search may filter on.
    $this->assertStringNotContainsString('describedby', json_encode($description, JSON_THROW_ON_ERROR));
  }

  /**
   * Tests that results hold only what the agent may read, at the time.
   */
  public function testAuthorization(): void {
    $shared = $this->createContainer('root/', 'shared');
    $visible = $this->create($shared, 'visible', ['Alpha']);
    $deep = $this->createContainer($shared, 'deep', ['Alpha']);
    $deeper = $this->create($deep, 'deeper', ['Alpha']);
    $private = $this->createContainer('root/', 'private');
    $hidden = $this->create($private, 'hidden', ['Alpha']);
    $single = $this->create($private, 'single', ['Alpha']);
    $alpha = ['type' => [self::T . 'Alpha']];

    $this->assertSame([$visible, $deep, $deeper, $hidden, $single], $this->find(self::ALICE, $alpha));
    // Nobody else sees anything, without a policy; nor does anyone without a
    // token, who may search.
    $this->assertSame([], $this->find(self::BOB, $alpha));
    $anonymous = $this->search(NULL, $alpha);
    $this->assertSame([], $this->found($anonymous));
    $this->assertSame(0, $this->json($anonymous)['totalItems']);
    // A token that was rejected is.
    $this->assertSame(401, $this->search(NULL, $alpha, ['Authorization' => 'Bearer nonsense'])->getStatusCode());

    $subtree = $this->letRead(self::BOB, [$shared]);
    $one = $this->letRead(self::BOB, [$single]);
    $response = $this->search(self::BOB, $alpha);
    $this->assertSame([$visible, $deep, $deeper, $single], $this->found($response));
    $this->assertSame(4, $this->json($response)['totalItems']);
    $data = ['type' => [self::T . 'Alpha', self::LWS_DATA]];
    $this->assertSame([$visible, $deeper, $single], $this->find(self::BOB, $data));

    // Revoking takes effect at once.
    $this->revoke($one);
    $this->assertSame([$visible, $deep, $deeper], $this->find(self::BOB, $alpha));
    $this->revoke($subtree);
    $this->assertSame([], $this->find(self::BOB, $alpha));

    // Public resources are found by anyone.
    $this->letRead(AccessPolicy::PUBLIC, [$visible]);
    $this->assertSame([$visible], $this->find(NULL, $alpha));

    // A policy on the whole storage with a constraint on the resource does
    // not let the agent read everything: each resource is checked.
    $this->create($private, 'picture', ['Alpha'], [], 'image/png');
    $this->letRead(self::BOB, [''], [['leftOperand' => 'format', 'operator' => 'eq', 'rightOperand' => 'image/png']]);
    $this->assertSame([$visible, 'root/private/picture'], $this->find(self::BOB, $alpha));
  }

  /**
   * Tests that the agent's scope narrows nothing it may read away.
   */
  public function testReadableTargets(): void {
    $storage = $this->container->get('lws_storage.storage_registry')->get('alice');
    $this->assertNotNull($storage);
    $decisions = $this->container->get(AccessDecisionInterface::class);
    $bob = new RequestingAgent(self::BOB, 'https://app.example/id', self::ISSUER);
    $this->assertSame([], $decisions->forAgent($bob, $storage)->readableTargets());
    $this->letRead(self::BOB, ['root/a/', 'root/b']);
    $this->assertSame([self::STORAGE . 'root/a/', self::STORAGE . 'root/b'], $decisions->forAgent($bob, $storage)->readableTargets());
    $this->letRead(AccessPolicy::AUTHENTICATED, ['']);
    $this->assertNull($decisions->forAgent($bob, $storage)->readableTargets());
    $alice = new RequestingAgent(self::ALICE, 'https://app.example/id', self::ISSUER);
    $this->assertNull($decisions->forAgent($alice, $storage)->readableTargets());
  }

}
