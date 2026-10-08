<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_storage\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AccountInterface;
use Drupal\lws_authz\Policy\AccessPolicy;
use Drupal\lws_authz\Policy\PolicyStore;
use Drupal\lws_storage\Controller\StorageAccessController;
use Drupal\lws_storage\Form\ShareForm;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests access policies at the storage (LWS Core §7.5, §11.3, DESIGN.md §6.5).
 *
 * Alice controls the storage. Bob and the public get what policies give them,
 * which shows in what they read, what they may write, and what listings of
 * the same container hold for each.
 */
#[Group('lws')]
#[RunTestsInSeparateProcesses]
final class PolicyAccessTest extends LwsStorageKernelTestBase {

  private const ALICE = 'https://id.example/alice';

  private const BOB = 'https://id.example/bob';

  private const CAROL = 'https://id.example/carol';

  private const ROOT = self::BASE . '/lws/alice/root/';

  private const SHARED = self::ROOT . 'shared/';

  private const PHOTO = 'https://type.example/Photo';

  private const CONTAINER = '<https://www.w3.org/ns/lws#Container>; rel="type"';

  /**
   * The policy store.
   */
  private PolicyStore $policies;

  /**
   * {@inheritdoc}
   *
   * The storage holds shared/ with a.txt, b.png (a Photo), d.html and
   * sub/x.txt, and private/c.txt.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->policies = $this->container->get('lws_authz.policy_store');
    $this->storages->createStorage('alice', 'Alice', [self::ALICE]);
    $this->agent = self::ALICE;
    foreach (['shared', 'private'] as $name) {
      $this->assertSame(201, $this->send('POST', '/lws/alice/root/', ['Link' => self::CONTAINER, 'Slug' => $name])->getStatusCode());
    }
    $this->assertSame(201, $this->send('POST', '/lws/alice/root/shared/', ['Link' => self::CONTAINER, 'Slug' => 'sub'])->getStatusCode());
    $files = [
      ['shared/', 'a.txt', 'text/plain', []],
      ['shared/', 'b.png', 'image/png', ['Link' => '<' . self::PHOTO . '>; rel="type"']],
      ['shared/', 'd.html', 'text/html', []],
      ['shared/sub/', 'x.txt', 'text/plain', []],
      ['private/', 'c.txt', 'text/plain', []],
    ];
    foreach ($files as [$container, $slug, $type, $headers]) {
      $headers += ['Content-Type' => $type, 'Slug' => $slug];
      $response = $this->send('POST', '/lws/alice/root/' . $container, $headers, 'content');
      $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    }
    $this->agent = NULL;
  }

  /**
   * Adds a policy to the storage.
   *
   * @param string $assignee
   *   The assignee.
   * @param list<string> $actions
   *   The actions.
   * @param string $targetType
   *   The target type, as a term.
   * @param list<string> $targets
   *   The target URIs.
   * @param list<array<string, mixed>> $constraints
   *   Constraint objects.
   *
   * @return int
   *   The policy ID.
   */
  private function share(string $assignee, array $actions, string $targetType, array $targets, array $constraints = []): int {
    $storage = $this->container->get('lws_storage.storage_registry')->get('alice');
    $this->assertNotNull($storage);
    $document = AccessPolicy::document($assignee, $actions, $targetType, $targets);
    $document['constraint'] = $constraints;
    $policy = $this->container->get('lws_authz.policy_parser')->parse($document, $storage);
    return (int) $this->policies->add($storage, $policy)->id();
  }

  /**
   * Sends a request as an agent, or anonymously.
   *
   * @param string|null $agent
   *   The agent; NULL for none.
   * @param string $method
   *   The method.
   * @param string $uri
   *   The URI.
   * @param array<string, string> $headers
   *   Headers.
   * @param string|null $body
   *   The body.
   * @param array<string, mixed> $claims
   *   Claims to add to or replace in the token.
   */
  private function as(?string $agent, string $method, string $uri, array $headers = [], ?string $body = NULL, array $claims = []): Response {
    if ($agent !== NULL) {
      $headers['Authorization'] = 'Bearer ' . $this->token($agent, $claims);
    }
    return $this->send($method, substr($uri, strlen(self::BASE)), $headers, $body);
  }

  /**
   * The status of a request as an agent.
   *
   * @param string|null $agent
   *   The agent; NULL for none.
   * @param string $method
   *   The method.
   * @param string $uri
   *   The URI.
   * @param array<string, string> $headers
   *   Headers.
   * @param string|null $body
   *   The body.
   */
  private function statusOf(?string $agent, string $method, string $uri, array $headers = [], ?string $body = NULL): int {
    return $this->as($agent, $method, $uri, $headers, $body)->getStatusCode();
  }

  /**
   * The URI of a resource's linkset.
   */
  private function linkset(string $uri): string {
    foreach ($this->as(self::ALICE, 'GET', $uri)->headers->all('link') as $link) {
      if (preg_match('/^<([^>]+)>; rel="linkset"/', (string) $link, $match) === 1) {
        return $match[1];
      }
    }
    $this->fail('No linkset for ' . $uri);
  }

  /**
   * The member names in an agent's listing of a container, and its total.
   *
   * @return array{list<string>, int}
   *   The names, sorted, and totalItems.
   */
  private function listing(?string $agent, string $uri): array {
    $response = $this->as($agent, 'GET', $uri);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $listing = $this->json($response);
    $names = array_map(static fn (array $item): string => substr($item['id'], strlen($uri)), $listing['items']);
    sort($names);
    return [$names, $listing['totalItems']];
  }

  /**
   * Tests that the same container lists differently for each agent.
   */
  public function testListings(): void {
    // Bob reads text, and the containers.
    $this->share(self::BOB, ['read'], 'StorageResource', [self::SHARED], [
      ['leftOperand' => 'format', 'operator' => 'eq', 'rightOperand' => 'text/plain'],
    ]);
    $this->share(self::BOB, ['read'], 'Container', [self::SHARED]);
    // Everyone reads the photos, and the containers.
    $this->share(AccessPolicy::PUBLIC, ['read'], 'DataResource', [self::SHARED], [
      ['leftOperand' => 'type', 'operator' => 'eq', 'rightOperand' => self::PHOTO],
    ]);
    $this->share(AccessPolicy::PUBLIC, ['read'], 'Container', [self::SHARED]);

    $this->assertSame([['a.txt', 'b.png', 'd.html', 'sub/'], 4], $this->listing(self::ALICE, self::SHARED));
    // Bob gets the public's too.
    $this->assertSame([['a.txt', 'b.png', 'sub/'], 3], $this->listing(self::BOB, self::SHARED));
    $this->assertSame([['b.png', 'sub/'], 2], $this->listing(NULL, self::SHARED));
    $this->assertSame([['x.txt'], 1], $this->listing(self::BOB, self::SHARED . 'sub/'));
    $this->assertSame([[], 0], $this->listing(NULL, self::SHARED . 'sub/'));
    // An authenticated agent with no policy of its own gets the public's.
    $this->assertSame([['b.png', 'sub/'], 2], $this->listing(self::CAROL, self::SHARED));

    // Reading what the listings show, and nothing else.
    $this->assertSame(200, $this->statusOf(self::BOB, 'GET', self::SHARED . 'a.txt'));
    $this->assertSame(200, $this->statusOf(self::BOB, 'GET', self::SHARED . 'sub/x.txt'));
    $this->assertSame(200, $this->statusOf(self::BOB, 'GET', self::SHARED . 'b.png'));
    $this->assertSame(403, $this->statusOf(self::BOB, 'GET', self::SHARED . 'd.html'));
    $this->assertSame(200, $this->statusOf(NULL, 'GET', self::SHARED . 'b.png'));
    $this->assertSame(401, $this->statusOf(NULL, 'GET', self::SHARED . 'a.txt'));
    $this->assertSame(403, $this->statusOf(self::CAROL, 'GET', self::SHARED . 'a.txt'));
    $this->assertSame(403, $this->statusOf(self::BOB, 'GET', self::ROOT . 'private/c.txt'));
    $this->assertSame(403, $this->statusOf(self::BOB, 'GET', self::ROOT));
    $this->assertSame(401, $this->statusOf(NULL, 'GET', self::ROOT . 'private/'));
    // A resource that does not exist has no format: refused as a resource
    // bob may not read would be, not 404.
    $this->assertSame(403, $this->statusOf(self::BOB, 'GET', self::SHARED . 'missing.txt'));
    // Linksets follow their resources.
    $linkset = $this->linkset(self::SHARED . 'a.txt');
    $this->assertSame(200, $this->statusOf(self::BOB, 'GET', $linkset));
    $this->assertSame(401, $this->statusOf(NULL, 'GET', $linkset));
    // Reading is not writing.
    $this->assertSame(403, $this->statusOf(self::BOB, 'PUT', self::SHARED . 'a.txt', ['Content-Type' => 'text/plain'], 'changed'));
    $this->assertSame(403, $this->statusOf(self::BOB, 'DELETE', self::SHARED . 'a.txt'));
    $this->assertSame(401, $this->statusOf(NULL, 'POST', self::SHARED, ['Content-Type' => 'image/png'], 'png'));
  }

  /**
   * Tests a policy over the whole storage, which lists as plainly as alice's.
   */
  public function testWholeStorage(): void {
    $this->share(self::BOB, ['read'], 'StorageResource', [self::BASE . '/lws/alice/']);
    $this->assertSame([['private/', 'shared/'], 2], $this->listing(self::BOB, self::ROOT));
    $this->assertSame(200, $this->statusOf(self::BOB, 'GET', self::ROOT . 'private/c.txt'));
    $this->assertSame(404, $this->statusOf(self::BOB, 'GET', self::ROOT . 'missing.txt'));
  }

  /**
   * Tests creating, judged on the container and on the new resource.
   */
  public function testCreate(): void {
    $this->share(self::BOB, ['create', 'read'], 'Container', [self::SHARED], [
      ['leftOperand' => 'format', 'operator' => 'isAnyOf', 'rightOperand' => ['image/png', 'image/jpeg']],
    ]);
    $png = ['Content-Type' => 'image/png'];
    $this->assertSame(201, $this->statusOf(self::BOB, 'POST', self::SHARED, $png, 'png'));
    $this->assertSame(201, $this->statusOf(self::BOB, 'POST', self::SHARED . 'sub/', ['Content-Type' => 'image/jpeg'], 'jpeg'));
    $this->assertSame(403, $this->statusOf(self::BOB, 'POST', self::SHARED, ['Content-Type' => 'text/html'], '<p>'));
    $this->assertSame(403, $this->statusOf(self::BOB, 'POST', self::SHARED, [], 'no type'));
    // A container has no format.
    $this->assertSame(403, $this->statusOf(self::BOB, 'POST', self::SHARED, ['Link' => self::CONTAINER]));
    $this->assertSame(403, $this->statusOf(self::BOB, 'POST', self::ROOT . 'private/', ['Content-Type' => 'image/png'], 'png'));

    // Only containers of photos.
    $this->share(self::CAROL, ['create'], 'StorageResource', [self::SHARED], [
      ['leftOperand' => 'type', 'operator' => 'eq', 'rightOperand' => self::PHOTO],
    ]);
    $photo = '<' . self::PHOTO . '>; rel="type"';
    $photoPng = ['Content-Type' => 'image/png', 'Link' => $photo];
    $this->assertSame(201, $this->statusOf(self::CAROL, 'POST', self::SHARED, $photoPng, 'png'));
    $this->assertSame(201, $this->statusOf(self::CAROL, 'POST', self::SHARED, ['Link' => self::CONTAINER . ', ' . $photo]));
    $this->assertSame(403, $this->statusOf(self::CAROL, 'POST', self::SHARED, ['Content-Type' => 'image/png'], 'png'));
  }

  /**
   * Tests modifying, judged before and after.
   */
  public function testModify(): void {
    $this->share(self::BOB, ['modify'], 'DataResource', [self::SHARED], [
      ['leftOperand' => 'format', 'operator' => 'eq', 'rightOperand' => 'image/png'],
    ]);
    $this->assertSame(204, $this->statusOf(self::BOB, 'PUT', self::SHARED . 'b.png', ['Content-Type' => 'image/png'], 'new png'));
    // Not into another format.
    $this->assertSame(403, $this->statusOf(self::BOB, 'PUT', self::SHARED . 'b.png', ['Content-Type' => 'text/html'], '<script>'));
    $this->assertSame(403, $this->statusOf(self::BOB, 'PUT', self::SHARED . 'a.txt', ['Content-Type' => 'image/png'], 'png'));
    // Its linkset may change, as its format does not.
    $linkset = $this->linkset(self::SHARED . 'b.png');
    $put = ['Content-Type' => 'application/linkset+json'];
    $context = [
      'anchor' => self::SHARED . 'b.png',
      'type' => [['href' => self::PHOTO]],
      'license' => [['href' => 'https://license.example/']],
    ];
    $document = json_encode(['linkset' => [$context]], JSON_THROW_ON_ERROR);
    $this->assertSame(204, $this->statusOf(self::BOB, 'PUT', $linkset, $put, $document));

    // Only photos stay photos.
    $this->share(self::CAROL, ['modify'], 'DataResource', [self::SHARED], [
      ['leftOperand' => 'type', 'operator' => 'eq', 'rightOperand' => self::PHOTO],
    ]);
    $photo = ['Content-Type' => 'image/png', 'Prefer' => 'set-linkset', 'Link' => '<' . self::PHOTO . '>; rel="type"'];
    $this->assertSame(204, $this->statusOf(self::CAROL, 'PUT', self::SHARED . 'b.png', ['Content-Type' => 'image/png'], 'png'));
    $this->assertSame(204, $this->statusOf(self::CAROL, 'PUT', self::SHARED . 'b.png', $photo, 'png'));
    $untyped = ['Content-Type' => 'image/png', 'Prefer' => 'set-linkset'];
    $this->assertSame(403, $this->statusOf(self::CAROL, 'PUT', self::SHARED . 'b.png', $untyped, 'png'));
    // A linkset write could make it anything.
    $this->assertSame(403, $this->statusOf(self::CAROL, 'PUT', $linkset, $put, $document));
  }

  /**
   * Tests time limits and clients, and every authenticated agent.
   */
  public function testConstraints(): void {
    $this->share(self::BOB, ['read'], 'StorageResource', [self::ROOT . 'private/'], [
      ['leftOperand' => 'dateTime', 'operator' => 'lteq', 'rightOperand' => gmdate('Y-m-d\TH:i:s\Z', time() - 60)],
    ]);
    $this->assertSame(403, $this->statusOf(self::BOB, 'GET', self::ROOT . 'private/c.txt'));
    $this->share(self::BOB, ['read'], 'StorageResource', [self::ROOT . 'private/'], [
      ['leftOperand' => 'dateTime', 'operator' => 'lteq', 'rightOperand' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600)],
      ['leftOperand' => 'client', 'operator' => 'eq', 'rightOperand' => 'https://app.example/id'],
    ]);
    $this->assertSame(200, $this->statusOf(self::BOB, 'GET', self::ROOT . 'private/c.txt'));
    $other = $this->as(self::BOB, 'GET', self::ROOT . 'private/c.txt', [], NULL, ['client_id' => 'https://other.example/id']);
    $this->assertSame(403, $other->getStatusCode());

    $this->share(AccessPolicy::AUTHENTICATED, ['read'], 'DataResource', [self::SHARED . 'a.txt']);
    $this->assertSame(200, $this->statusOf(self::CAROL, 'GET', self::SHARED . 'a.txt'));
    $this->assertSame(401, $this->statusOf(NULL, 'GET', self::SHARED . 'a.txt'));

    // Purpose constraints never hold: no request states a purpose.
    $this->share(self::CAROL, ['read'], 'StorageResource', [self::ROOT . 'private/'], [
      ['leftOperand' => 'purpose', 'operator' => 'eq', 'rightOperand' => 'https://purpose.example/research'],
    ]);
    $this->assertSame(403, $this->statusOf(self::CAROL, 'GET', self::ROOT . 'private/c.txt'));
  }

  /**
   * Tests that removing a policy, or the storage, takes effect at once.
   */
  public function testRevocation(): void {
    $id = $this->share(self::BOB, ['read'], 'StorageResource', [self::SHARED]);
    $this->assertSame(200, $this->statusOf(self::BOB, 'GET', self::SHARED . 'a.txt'));
    $storage = $this->container->get('lws_storage.storage_registry')->get('alice');
    $this->assertNotNull($storage);
    $this->policies->load($storage->id, $id)?->delete();
    $this->assertSame(403, $this->statusOf(self::BOB, 'GET', self::SHARED . 'a.txt'));

    // Expired policies made by administrators are purged.
    $this->share(self::BOB, ['read'], 'StorageResource', [self::SHARED], [
      ['leftOperand' => 'dateTime', 'operator' => 'lt', 'rightOperand' => gmdate('Y-m-d\TH:i:s\Z', time() - 60)],
    ]);
    $this->share(self::BOB, ['read'], 'StorageResource', [self::SHARED]);
    $this->assertCount(2, $this->policies->forStorage($storage->id));
    $this->container->get('module_handler')->invoke('lws_authz', 'cron');
    $this->assertCount(1, $this->policies->forStorage($storage->id));

    $this->loadStorage('alice')->delete();
    $this->assertSame([], $this->policies->forStorage($storage->id));
  }

  /**
   * Tests concealing existence: refusals of valid tokens become 404.
   */
  public function testConcealExistence(): void {
    $this->config('lws.settings')->set('conceal_existence', TRUE)->save();
    $response = $this->as(self::BOB, 'GET', self::ROOT . 'private/c.txt');
    $this->assertProblem($response, 404, self::ROOT . 'private/c.txt');
    $this->assertProblem($this->as(self::BOB, 'GET', self::ROOT . 'private/missing.txt'), 404, self::ROOT . 'private/missing.txt');
    // Without a token, the challenge stays.
    $this->assertSame(401, $this->statusOf(NULL, 'GET', self::ROOT . 'private/c.txt'));
  }

  /**
   * Tests the Share form and who may use it.
   */
  public function testShareForm(): void {
    $storage = $this->loadStorage('alice');
    $storage->set('owner', 7)->save();
    $formState = (new FormState())->setValues([
      'who' => 'agent',
      'agent' => self::BOB,
      'actions' => ['read' => 'read', 'create' => 'create'],
      'target_type' => 'https://www.w3.org/ns/lws#StorageResource',
      'targets' => self::SHARED . "\n" . self::ROOT . 'private/c.txt',
      'client' => '',
      'op' => 'Share',
    ]);
    $this->container->get('form_builder')->submitForm(ShareForm::class, $formState, $storage);
    $this->assertSame([], $formState->getErrors());
    $policies = $this->policies->forStorage((int) $storage->id());
    $this->assertCount(1, $policies);
    $policy = reset($policies)->toAccessPolicy();
    $this->assertSame(['read', 'create'], $policy->actions);
    $this->assertSame([self::SHARED, self::ROOT . 'private/c.txt'], $policy->targetValues);
    $this->assertSame(200, $this->statusOf(self::BOB, 'GET', self::ROOT . 'private/c.txt'));

    // What the parser refuses, the form refuses.
    $formState = (new FormState())->setValues([
      'who' => AccessPolicy::PUBLIC,
      'actions' => ['read' => 'read'],
      'target_type' => 'https://www.w3.org/ns/lws#StorageResource',
      'targets' => 'https://elsewhere.example/',
      'op' => 'Share',
    ]);
    $this->container->get('form_builder')->submitForm(ShareForm::class, $formState, $storage);
    $this->assertStringContainsString('not in the storage', (string) ($formState->getErrors()['share'] ?? ''));
    $this->assertCount(1, $this->policies->forStorage((int) $storage->id()));

    // Administrators, and the owner with the permission to manage it.
    $account = function (int $uid, array $permissions): AccountInterface {
      $account = $this->createMock(AccountInterface::class);
      $account->method('id')->willReturn($uid);
      $account->method('hasPermission')->willReturnCallback(static fn (string $permission): bool => in_array($permission, $permissions, TRUE));
      return $account;
    };
    $this->assertTrue(StorageAccessController::access($account(1, ['administer lws storages']), $storage)->isAllowed());
    $this->assertTrue(StorageAccessController::access($account(7, ['manage own lws storages']), $storage)->isAllowed());
    $this->assertFalse(StorageAccessController::access($account(8, ['manage own lws storages']), $storage)->isAllowed());
    $this->assertFalse(StorageAccessController::access($account(7, []), $storage)->isAllowed());
  }

}
