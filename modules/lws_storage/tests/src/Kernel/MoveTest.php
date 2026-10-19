<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_storage\Kernel;

use Drupal\lws_authz\Policy\AccessPolicy;
use Drupal\lws_storage\ResourceMoves;
use Ebremer\Lws\Http\LinkHeader;
use Ebremer\Lws\Json\JsonPatch;
use Ebremer\Lws\Model\Linkset;
use Ebremer\Lws\Model\StorageDescription;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests moving resources between containers (LWS Core, Moving Resources).
 *
 * Alice controls the storage, which holds notes/ and archive/. A move is a
 * change of the "up" link of a resource's linkset; here the resource takes a
 * new URI in its new container, and its former URIs redirect there.
 */
#[Group('lws')]
#[RunTestsInSeparateProcesses]
final class MoveTest extends LwsStorageKernelTestBase {

  private const ALICE = 'https://id.example/alice';

  private const BOB = 'https://id.example/bob';

  private const CAROL = 'https://id.example/carol';

  private const STORAGE = self::BASE . '/lws/alice/';

  private const ROOT = self::STORAGE . 'root/';

  private const NOTES = self::ROOT . 'notes/';

  private const ARCHIVE = self::ROOT . 'archive/';

  private const LICENSE = 'https://creativecommons.org/licenses/by/4.0/';

  private const CONTAINER = '<https://www.w3.org/ns/lws#Container>; rel="type"';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->storages->createStorage('alice', 'Alice', [self::ALICE]);
    $this->agent = self::ALICE;
    $this->container('root/', 'notes');
    $this->container('root/', 'archive');
  }

  /**
   * Creates a container and returns its URI.
   */
  private function container(string $parent, string $slug): string {
    $response = $this->send('POST', '/lws/alice/' . $parent, ['Link' => self::CONTAINER, 'Slug' => $slug]);
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    return (string) $response->headers->get('Location');
  }

  /**
   * Creates a data resource and returns its URI.
   *
   * @param string $parent
   *   The container's path below the storage URI.
   * @param string $slug
   *   The identity hint.
   * @param string $body
   *   The content.
   * @param list<string> $links
   *   Link header values.
   */
  private function create(string $parent, string $slug, string $body = 'content', array $links = []): string {
    $headers = ['Content-Type' => 'text/plain', 'Slug' => $slug];
    if ($links !== []) {
      $headers['Link'] = $links;
    }
    $response = $this->send('POST', '/lws/alice/' . $parent, $headers, $body);
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
   * Sends a JSON Patch to a linkset.
   *
   * @param string $linkset
   *   The linkset URI.
   * @param \Ebremer\Lws\Json\JsonPatch $patch
   *   The patch.
   * @param array<string, string> $headers
   *   Further headers.
   */
  private function patch(string $linkset, JsonPatch $patch, array $headers = []): Response {
    return $this->send('PATCH', $this->path($linkset), $headers + ['Content-Type' => 'application/json-patch+json'], (string) $patch);
  }

  /**
   * Moves a resource by replacing the target of its "up" link.
   *
   * @param string $linkset
   *   Its linkset's URI.
   * @param string $destination
   *   The URI the "up" link is to name.
   * @param array<string, string> $headers
   *   Further headers.
   */
  private function move(string $linkset, string $destination, array $headers = []): Response {
    return $this->patch($linkset, (new JsonPatch())->replace('/linkset/0/up/0/href', $destination), $headers);
  }

  /**
   * The URIs a container lists.
   *
   * @return list<string>
   *   The members' URIs, sorted: a listing is in the database's collation
   *   order, and PostgreSQL's puts "archive/" before "a.txt".
   */
  private function members(string $container): array {
    $response = $this->send('GET', $this->path($container));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $uris = array_column($this->json($response)['items'], 'id');
    sort($uris, SORT_STRING);
    return $uris;
  }

  /**
   * The entity tag of a GET of a resource.
   */
  private function etag(string $uri): string {
    return (string) $this->send('GET', $this->path($uri))->getEtag();
  }

  /**
   * Adds a policy to the storage.
   *
   * @param string $assignee
   *   The assignee.
   * @param list<string> $actions
   *   The actions.
   * @param list<string> $targets
   *   The target URIs.
   * @param string $targetType
   *   The target type, as a term.
   */
  private function share(string $assignee, array $actions, array $targets, string $targetType = 'StorageResource'): void {
    $storage = $this->container->get('lws_storage.storage_registry')->get('alice');
    $this->assertNotNull($storage);
    $policy = $this->container->get('lws_authz.policy_parser')->parse(AccessPolicy::document($assignee, $actions, $targetType, $targets), $storage);
    $this->container->get('lws_authz.policy_store')->add($storage, $policy);
  }

  /**
   * Sends a request as another agent, or anonymously.
   *
   * @param string|null $agent
   *   The agent; NULL for none.
   * @param \Closure(): \Symfony\Component\HttpFoundation\Response $request
   *   Sends the request.
   */
  private function as(?string $agent, \Closure $request): Response {
    $before = $this->agent;
    $this->agent = $agent;
    try {
      return $request();
    }
    finally {
      $this->agent = $before;
    }
  }

  /**
   * Tests the MoveResource capability in every form of the description.
   */
  public function testCapability(): void {
    foreach (['application/lws+cid', 'application/ld+json', 'application/json'] as $type) {
      $description = $this->json($this->send('GET', '/lws/alice/', ['Accept' => $type]));
      $this->assertContains([
        'type' => 'MoveResource',
        'appliesTo' => ['DataResource', 'Container'],
        'preservesIdentifier' => FALSE,
      ], $description['capability'], $type);
    }
    $move = StorageDescription::parse($this->json($this->send('GET', '/lws/alice/')), self::STORAGE)->moveCapability();
    $this->assertNotNull($move);
    $this->assertTrue($move->movesDataResources());
    $this->assertTrue($move->movesContainers());
    $this->assertFalse($move->preservesIdentifier);

    $this->config('lws_storage.settings')->set('move_applies_to', ['DataResource'])->save();
    $move = StorageDescription::parse($this->json($this->send('GET', '/lws/alice/')), self::STORAGE)->moveCapability();
    $this->assertNotNull($move);
    $this->assertSame(['DataResource'], $move->appliesTo);

    $this->config('lws_storage.settings')->set('move_applies_to', [])->save();
    $description = $this->json($this->send('GET', '/lws/alice/'));
    $this->assertSame(['https://www.w3.org/ns/lws#PatchSupport'], array_column($description['capability'], 'type'));
    $this->assertNull(StorageDescription::parse($description, self::STORAGE)->moveCapability());
  }

  /**
   * Tests the update that adds moving to an existing site.
   */
  public function testUpdate(): void {
    $schema = $this->container->get('database')->schema();
    $schema->dropTable(ResourceMoves::TABLE);
    $this->config('lws_storage.settings')->clear('move_applies_to')->save();
    $this->container->get('module_handler')->loadInclude('lws_storage', 'install');
    $this->assertStringContainsString('now', lws_storage_update_11002());
    $this->assertTrue($schema->tableExists(ResourceMoves::TABLE));
    $this->assertSame(['DataResource', 'Container'], $this->config('lws_storage.settings')->get('move_applies_to'));
    $this->assertStringContainsString('already', lws_storage_update_11002());
  }

  /**
   * Tests the draft's example: a data resource moves to another container.
   */
  public function testMoveDataResource(): void {
    $uri = $this->create('root/notes/', 'shoppinglist.txt', 'milk, eggs', ['<' . self::LICENSE . '>; rel="license"']);
    $linkset = $this->linksetUri($uri);
    $read = $this->send('GET', $this->path($linkset));
    $notesTag = $this->etag(self::NOTES);
    $archiveTag = $this->etag(self::ARCHIVE);
    $contentTag = $this->etag($uri);

    $patch = (new JsonPatch())->test('/linkset/0/anchor', $uri)->replace('/linkset/0/up/0/href', self::ARCHIVE);
    $headers = ['If-Match' => (string) $read->getEtag(), 'Origin' => 'https://app.example'];
    $response = $this->patch($linkset, $patch, $headers);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame('application/linkset+json', $response->headers->get('Content-Type'));
    $this->assertSame($linkset, $response->headers->get('Content-Location'));
    $this->assertContains('Content-Location', array_map('trim', explode(',', (string) $response->headers->get('Access-Control-Expose-Headers'))));
    $moved = self::ARCHIVE . 'shoppinglist.txt';
    $document = Linkset::parse($this->json($response));
    $this->assertSame([self::ARCHIVE], $document->hrefs('up', $moved));
    $this->assertSame([self::LICENSE], $document->hrefs('license', $moved));
    $this->assertSame($moved, $this->json($response)['linkset'][0]['anchor']);
    $after = $this->send('GET', $this->path($linkset));
    $this->assertSame($after->getEtag(), $response->getEtag());
    $this->assertSame((string) $after->getContent(), (string) $response->getContent());

    // It left one listing for the other, whose entity tags both changed.
    $this->assertSame([], $this->members(self::NOTES));
    $this->assertSame([$moved], $this->members(self::ARCHIVE));
    $this->assertNotSame($notesTag, $this->etag(self::NOTES));
    $this->assertNotSame($archiveTag, $this->etag(self::ARCHIVE));
    // Its content and links stay, and its linkset keeps its URL.
    $read = $this->send('GET', $this->path($moved));
    $this->assertSame(200, $read->getStatusCode());
    $this->assertSame('milk, eggs', $this->body($read));
    $this->assertSame($contentTag, $read->getEtag());
    $this->assertSame([self::ARCHIVE], $this->linkTargets($read, 'up'));
    $this->assertSame([$linkset], $this->linkTargets($read, 'linkset'));

    // Its former URI redirects, for those who may read it where it is now.
    foreach (['GET', 'HEAD'] as $method) {
      $redirect = $this->send($method, $this->path($uri));
      $this->assertSame(301, $redirect->getStatusCode(), $method);
      $this->assertSame($moved, $redirect->headers->get('Location'));
    }
    $this->assertSame($moved . '?page=x', $this->send('GET', $this->path($uri) . '?page=x')->headers->get('Location'));
    // Bob may read notes/, where it was, not archive/, where it is: to Bob it
    // is gone, as anything that was never there.
    $this->share(self::BOB, ['read'], [self::NOTES]);
    $this->assertProblem($this->as(self::BOB, fn () => $this->send('GET', $this->path($uri))), 404, $uri);
    $this->assertProblem($this->as(self::BOB, fn () => $this->send('GET', '/lws/alice/root/notes/never.txt')), 404, self::NOTES . 'never.txt');
    $this->assertSame(401, $this->as(NULL, fn () => $this->send('GET', $this->path($uri)))->getStatusCode());
    // Carol may read archive/ only, which a read of the resource where it is
    // now is: a redirect there, and only that.
    $this->share(self::CAROL, ['read'], [self::ARCHIVE]);
    $redirect = $this->as(self::CAROL, fn () => $this->send('GET', $this->path($uri)));
    $this->assertSame(301, $redirect->getStatusCode());
    $this->assertSame($moved, $redirect->headers->get('Location'));
    $this->assertSame(403, $this->as(self::CAROL, fn () => $this->send('GET', '/lws/alice/root/notes/never.txt'))->getStatusCode());
    // A resource created at the former URI takes it over.
    $this->assertSame($uri, $this->create('root/notes/', 'shoppinglist.txt', 'new'));
    $this->assertSame('new', $this->body($this->send('GET', $this->path($uri))));
    $this->assertSame(403, $this->as(self::CAROL, fn () => $this->send('GET', $this->path($uri)))->getStatusCode());
  }

  /**
   * Tests moving a container with what it holds.
   */
  public function testMoveContainer(): void {
    $projects = $this->container('root/notes/', 'projects');
    $plan = $this->create('root/notes/projects/', 'plan.txt', 'the plan');
    $this->container('root/notes/projects/', 'old');
    $draft = $this->create('root/notes/projects/old/', 'draft.txt', 'a draft');
    $draftLinkset = $this->linksetUri($draft);
    $linkset = $this->linksetUri($projects);
    $projectsTag = $this->etag($projects);

    $response = $this->move($linkset, self::ARCHIVE);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $moved = self::ARCHIVE . 'projects/';
    $this->assertSame($moved, $this->json($response)['linkset'][0]['anchor']);
    $this->assertSame([], $this->members(self::NOTES));
    $this->assertSame([$moved], $this->members(self::ARCHIVE));
    $this->assertSame([$moved . 'old/', $moved . 'plan.txt'], $this->members($moved));
    $this->assertNotSame($projectsTag, $this->etag($moved));
    // What it holds moved along, content, linksets and all.
    $this->assertSame('a draft', $this->body($this->send('GET', '/lws/alice/root/archive/projects/old/draft.txt')));
    $this->assertSame([$draftLinkset], $this->linkTargets($this->send('GET', '/lws/alice/root/archive/projects/old/draft.txt'), 'linkset'));
    $document = $this->json($this->send('GET', $this->path($draftLinkset)));
    $this->assertSame($moved . 'old/draft.txt', $document['linkset'][0]['anchor']);
    $this->assertSame([['href' => $moved . 'old/']], $document['linkset'][0]['up']);
    $this->assertNull($this->resources->findByPath($this->loadStorage('alice'), 'root/notes/projects/old/draft.txt'));
    // Their former URIs redirect, each to where it is now.
    $this->assertSame($moved . 'old/draft.txt', $this->send('GET', $this->path($draft))->headers->get('Location'));
    $this->assertSame($moved . 'plan.txt', $this->send('HEAD', $this->path($plan))->headers->get('Location'));
    $this->assertSame($moved . 'old/', $this->send('GET', '/lws/alice/root/notes/projects/old/')->headers->get('Location'));
    $this->assertSame(404, $this->send('GET', '/lws/alice/root/notes/projects/missing.txt')->getStatusCode());
    // Only GET and HEAD.
    $this->assertSame(404, $this->send('PUT', $this->path($plan), ['Content-Type' => 'text/plain'], 'x')->getStatusCode());
    $this->assertSame(404, $this->send('DELETE', $this->path($plan))->getStatusCode());

    // A container created where the moved one was takes its URIs over.
    $this->container('root/notes/', 'projects');
    $this->assertSame(404, $this->send('GET', $this->path($plan))->getStatusCode());
  }

  /**
   * Tests that a former URI follows the moves that came after.
   */
  public function testMovedTwice(): void {
    $this->container('root/notes/', 'sub');
    $x = $this->create('root/notes/sub/', 'x.txt');
    // notes/ moves into archive/, then sub/ out of it to the root.
    $this->assertSame(200, $this->move($this->linksetUri(self::NOTES), self::ARCHIVE)->getStatusCode());
    $this->assertSame(200, $this->move($this->linksetUri(self::ARCHIVE . 'notes/sub/'), self::ROOT)->getStatusCode());
    $this->assertSame(self::ROOT . 'sub/x.txt', $this->send('GET', $this->path($x))->headers->get('Location'));
    $this->assertSame(self::ROOT . 'sub/x.txt', $this->send('GET', '/lws/alice/root/archive/notes/sub/x.txt')->headers->get('Location'));
    // Deleted, it redirects nowhere, and nothing is kept of it.
    $this->assertSame(204, $this->send('DELETE', '/lws/alice/root/sub/', ['Depth' => 'infinity'])->getStatusCode());
    $this->assertSame(404, $this->send('GET', $this->path($x))->getStatusCode());
    $kept = $this->container->get('database')->select(ResourceMoves::TABLE, 'm')->fields('m', ['path'])->execute()?->fetchCol();
    $this->assertSame(['root/notes/'], $kept);
  }

  /**
   * Tests moving back to where it was, and the PUT and relative forms.
   */
  public function testForms(): void {
    $uri = $this->create('root/notes/', 'a.txt');
    $linkset = $this->linksetUri($uri);
    // A PUT that names another container moves the resource.
    $document = ['linkset' => [['up' => [['href' => self::ARCHIVE]], 'license' => [['href' => self::LICENSE]]]]];
    $response = $this->send('PUT', $this->path($linkset), ['Content-Type' => 'application/linkset+json'], (string) json_encode($document, JSON_UNESCAPED_SLASHES));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame([self::ARCHIVE . 'a.txt'], $this->members(self::ARCHIVE));
    // One that leaves "up" out, or names where it is, leaves it there.
    $document = ['linkset' => [['describedby' => [['href' => 'https://schema.example/a']]]]];
    $this->assertSame(204, $this->send('PUT', $this->path($linkset), ['Content-Type' => 'application/linkset+json'], (string) json_encode($document, JSON_UNESCAPED_SLASHES))->getStatusCode());
    $document['linkset'][0]['up'] = [['href' => '/lws/alice/root/archive/']];
    $this->assertSame(204, $this->send('PUT', $this->path($linkset), ['Content-Type' => 'application/linkset+json'], (string) json_encode($document, JSON_UNESCAPED_SLASHES))->getStatusCode());
    // The same container, spelt otherwise.
    $this->assertSame(204, $this->move($linkset, 'HTTPS://Storage.Example/lws/alice/root/%61rchive/')->getStatusCode());
    $this->assertSame([self::ARCHIVE . 'a.txt'], $this->members(self::ARCHIVE));

    // A relative reference resolves against the linkset's URL, as in the
    // draft's example.
    $this->assertSame(200, $this->move($linkset, '/lws/alice/root/notes/')->getStatusCode());
    $this->assertSame([$uri], $this->members(self::NOTES));
    $this->assertSame(200, $this->move($linkset, '../root/')->getStatusCode());
    $this->assertSame([self::ROOT . 'a.txt', self::ARCHIVE, self::NOTES], $this->members(self::ROOT));
    // Back where it was, its URI is its own again, and a former one leads to
    // it.
    $this->assertSame(200, $this->move($linkset, self::NOTES)->getStatusCode());
    $this->assertSame(200, $this->send('GET', $this->path($uri))->getStatusCode());
    $this->assertSame($uri, $this->send('GET', '/lws/alice/root/archive/a.txt')->headers->get('Location'));
    $this->assertSame($uri, $this->send('GET', '/lws/alice/root/a.txt')->headers->get('Location'));
    $this->assertSame(['https://schema.example/a'], Linkset::parse($this->json($this->send('GET', $this->path($linkset))))->hrefs('describedby', $uri));
  }

  /**
   * Tests a move into a container that holds the name already.
   */
  public function testNameTaken(): void {
    $this->create('root/archive/', 'note.txt', 'archived');
    $this->container('root/archive/', 'x');
    $note = $this->create('root/notes/', 'note.txt', 'current');
    $x = $this->create('root/notes/', 'x', 'a data resource named as a container there is');
    $response = $this->move($this->linksetUri($note), self::ARCHIVE);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(self::ARCHIVE . 'note-1.txt', $this->json($response)['linkset'][0]['anchor']);
    $this->assertSame('current', $this->body($this->send('GET', '/lws/alice/root/archive/note-1.txt')));
    $this->assertSame('archived', $this->body($this->send('GET', '/lws/alice/root/archive/note.txt')));
    $this->assertSame(self::ARCHIVE . 'note-1.txt', $this->send('GET', $this->path($note))->headers->get('Location'));
    $response = $this->move($this->linksetUri($x), self::ARCHIVE);
    $this->assertSame(self::ARCHIVE . 'x-1', $this->json($response)['linkset'][0]['anchor']);
  }

  /**
   * Tests the moves that are refused, which change nothing.
   */
  public function testRefused(): void {
    $this->storages->createStorage('bob', 'Bob', [self::ALICE]);
    $inner = $this->container('root/notes/', '2026');
    $file = $this->create('root/archive/', 'file.txt');
    $note = $this->create('root/notes/', 'note.txt');
    $notes = $this->linksetUri(self::NOTES);
    $noteLinkset = $this->linksetUri($note);
    $before = [$this->etag($notes), $this->etag($noteLinkset), $this->etag(self::NOTES), $this->etag(self::ARCHIVE)];

    // A container cannot move into itself, nor into a container it holds.
    $response = $this->move($notes, $inner);
    $this->assertProblem($response, 409, $notes);
    $this->assertSame('Cannot move a container into one of its own descendants: ' . $inner . ' is a descendant of ' . self::NOTES . '.', $this->json($response)['detail']);
    $this->assertProblem($this->move($notes, self::NOTES), 409, $notes);
    $this->assertStringStartsWith('Cannot move a container into itself', $this->json($this->move($notes, self::NOTES))['detail']);
    // The destination must be a container of the storage that exists.
    $this->assertProblem($this->move($noteLinkset, self::ROOT . 'nowhere/'), 409, $noteLinkset);
    $this->assertProblem($this->move($noteLinkset, $file), 409, $noteLinkset);
    $this->assertProblem($this->move($noteLinkset, $file . '/'), 409, $noteLinkset);
    $this->assertProblem($this->move($noteLinkset, self::STORAGE), 409, $noteLinkset);
    $this->assertProblem($this->move($noteLinkset, $noteLinkset), 409, $noteLinkset);
    $this->assertProblem($this->move($noteLinkset, 'https://elsewhere.example/root/'), 409, $noteLinkset);
    $this->assertProblem($this->move($noteLinkset, 'urn:example:container'), 409, $noteLinkset);
    $response = $this->move($noteLinkset, self::BASE . '/lws/bob/root/');
    $this->assertProblem($response, 409, $noteLinkset);
    $this->assertStringContainsString('own storage', $this->json($response)['detail']);
    $this->assertProblem($this->patch($noteLinkset, (new JsonPatch())->replace('/linkset/0/up/0/href', 42)), 422, $noteLinkset);
    // It stays in one container.
    $this->assertProblem($this->patch($noteLinkset, (new JsonPatch())->remove('/linkset/0/up')), 409, $noteLinkset);
    $this->assertProblem($this->patch($noteLinkset, (new JsonPatch())->replace('/linkset/0/up', [])), 409, $noteLinkset);
    $this->assertProblem($this->patch($noteLinkset, (new JsonPatch())->add('/linkset/0/up/-', ['href' => self::ARCHIVE])), 409, $noteLinkset);
    $document = ['linkset' => [['up' => [['href' => self::ARCHIVE], ['href' => self::NOTES]]]]];
    $this->assertProblem($this->send('PUT', $this->path($noteLinkset), ['Content-Type' => 'application/linkset+json'], (string) json_encode($document)), 409, $noteLinkset);
    // The root is in no container.
    $root = $this->linksetUri(self::ROOT);
    $this->assertProblem($this->patch($root, (new JsonPatch())->add('/linkset/0/up', [['href' => self::ARCHIVE]])), 409, $root);
    // A stale entity tag.
    $this->assertProblem($this->move($noteLinkset, self::ARCHIVE, ['If-Match' => '"stale"']), 412, $noteLinkset);

    $after = [$this->etag($notes), $this->etag($noteLinkset), $this->etag(self::NOTES), $this->etag(self::ARCHIVE)];
    $this->assertSame($before, $after);
    $this->assertSame([$file], $this->members(self::ARCHIVE));
  }

  /**
   * Tests a storage that lets clients move nothing, or data resources only.
   */
  public function testSettings(): void {
    $note = $this->create('root/notes/', 'note.txt');
    $noteLinkset = $this->linksetUri($note);
    $notes = $this->linksetUri(self::NOTES);

    $this->config('lws_storage.settings')->set('move_applies_to', [])->save();
    $response = $this->move($noteLinkset, self::ARCHIVE);
    $this->assertProblem($response, 409, $noteLinkset);
    $this->assertSame('The "up" relation is server-managed and cannot be changed.', $this->json($response)['detail']);
    // As before there were moves, whatever "up" names.
    $this->assertProblem($this->move($noteLinkset, 'https://linkset.invalid/forged-parent/'), 409, $noteLinkset);
    $this->assertSame(204, $this->move($noteLinkset, self::NOTES)->getStatusCode());
    $this->assertSame([], $this->members(self::ARCHIVE));

    $this->config('lws_storage.settings')->set('move_applies_to', ['DataResource'])->save();
    $this->assertProblem($this->move($notes, self::ARCHIVE), 409, $notes);
    $this->assertSame([], $this->members(self::ARCHIVE));
    $this->assertSame(200, $this->move($noteLinkset, self::ARCHIVE)->getStatusCode());

    $this->config('lws_storage.settings')->set('move_applies_to', ['Container'])->save();
    $this->assertProblem($this->move($noteLinkset, self::NOTES), 409, $noteLinkset);
    $this->assertSame(200, $this->move($notes, self::ARCHIVE)->getStatusCode());
  }

  /**
   * Tests the limits a move of a container keeps to.
   */
  public function testLimits(): void {
    $this->create('root/notes/', 'a.txt');
    $this->create('root/notes/', 'b.txt');
    $notes = $this->linksetUri(self::NOTES);
    $this->config('lws_storage.settings')->set('max_recursive_delete', 1)->save();
    $response = $this->move($notes, self::ARCHIVE);
    $this->assertProblem($response, 422, $notes);
    $this->assertStringNotContainsString('2', (string) $this->json($response)['detail']);

    // Paths may not grow too long: what nests deepest there decides.
    $this->config('lws_storage.settings')->set('max_recursive_delete', 1000)->save();
    $path = 'root/archive/';
    for ($depth = 0; $depth < 10; $depth++) {
      $path = substr($this->container($path, str_repeat('n', 199) . $depth), strlen(self::STORAGE));
    }
    $this->assertSame(200, $this->move($this->linksetUri(self::ROOT . 'notes/a.txt'), self::STORAGE . $path)->getStatusCode());
    $this->container('root/notes/', str_repeat('m', 200));
    $response = $this->move($notes, self::STORAGE . $path);
    $this->assertProblem($response, 409, $notes);
    $this->assertSame([self::ROOT . 'notes/b.txt', self::ROOT . 'notes/' . str_repeat('m', 200) . '/'], $this->members(self::NOTES));
  }

  /**
   * Tests that a move needs deleting the resource and creating there.
   */
  public function testAuthorization(): void {
    $note = $this->create('root/notes/', 'note.txt');
    $projects = $this->container('root/notes/', 'projects');
    $this->create('root/notes/projects/', 'plan.txt');
    $noteLinkset = $this->linksetUri($note);
    $projectsLinkset = $this->linksetUri($projects);
    $this->agent = self::BOB;

    // Bob may change the note's linkset, and create in archive/, but not
    // delete the note.
    $this->share(self::BOB, ['read', 'modify'], [self::NOTES]);
    $this->share(self::BOB, ['read', 'create'], [self::ARCHIVE]);
    $this->assertSame(204, $this->patch($noteLinkset, (new JsonPatch())->add('/linkset/0/license', [['href' => self::LICENSE]]))->getStatusCode());
    $this->assertProblem($this->move($noteLinkset, self::ARCHIVE), 403, $noteLinkset);
    $this->assertSame([], $this->members(self::ARCHIVE));

    // With delete, Bob may move it, but only where Bob may create.
    $this->share(self::BOB, ['delete'], [$note]);
    $this->assertProblem($this->move($noteLinkset, self::ROOT), 403, $noteLinkset);
    // A container that does not exist is no different, to Bob.
    $this->assertProblem($this->move($noteLinkset, self::ROOT . 'nowhere/'), 403, $noteLinkset);
    $this->assertProblem($this->move($noteLinkset, self::ARCHIVE . 'nowhere/'), 409, $noteLinkset);
    $this->assertSame(200, $this->move($noteLinkset, self::ARCHIVE)->getStatusCode());

    // Moving a container needs deleting everything in it: Bob may delete
    // projects/, but not plan.txt in it.
    $this->share(self::BOB, ['delete'], [$projects], 'Container');
    $this->assertProblem($this->move($projectsLinkset, self::ARCHIVE), 403, $projectsLinkset);
    $this->assertSame([self::ARCHIVE . 'note.txt'], $this->members(self::ARCHIVE));
    $this->assertSame(200, $this->send('GET', '/lws/alice/root/notes/projects/plan.txt')->getStatusCode());
    $this->share(self::BOB, ['delete'], [$projects . 'plan.txt']);
    $this->assertSame(200, $this->move($projectsLinkset, self::ARCHIVE)->getStatusCode());

    // Without a token: the challenge, as for any write.
    $this->agent = NULL;
    $this->assertSame(401, $this->move($noteLinkset, self::NOTES)->getStatusCode());
  }

}
