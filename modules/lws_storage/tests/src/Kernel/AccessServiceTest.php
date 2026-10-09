<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_storage\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\lws_authz\AccessService\AccessRecordEvent;
use Drupal\lws_authz\Entity\LwsAccessRecordInterface;
use Drupal\lws_authz\Entity\LwsPolicyInterface;
use Drupal\lws_storage\Form\AccessRequestForm;
use Drupal\lws_storage\Form\PolicyDeleteForm;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the access request and access grant services (LWS Core §11).
 */
#[Group('lws')]
#[RunTestsInSeparateProcesses]
final class AccessServiceTest extends LwsStorageKernelTestBase {

  private const ALICE = 'https://id.example/alice';

  private const BOB = 'https://id.example/bob';

  private const CAROL = 'https://id.example/carol';

  private const STORAGE = self::BASE . '/lws/alice/';

  private const SHARED = self::STORAGE . 'root/shared/';

  private const GRANTS = self::STORAGE . 'access/grants/';

  private const REQUESTS = self::STORAGE . 'access/requests/';

  /**
   * The events dispatched about requests and grants.
   *
   * @var list<array{string, string, string}>
   */
  private array $events = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->storages->createStorage('alice', 'Alice', [self::ALICE]);
    $this->agent = self::ALICE;
    $container = ['Link' => '<https://www.w3.org/ns/lws#Container>; rel="type"', 'Slug' => 'shared'];
    $this->assertSame(201, $this->send('POST', '/lws/alice/root/', $container)->getStatusCode());
    $text = ['Content-Type' => 'text/plain', 'Slug' => 'a.txt'];
    $this->assertSame(201, $this->send('POST', '/lws/alice/root/shared/', $text, 'a')->getStatusCode());
    $this->agent = NULL;
    $dispatcher = $this->container->get('event_dispatcher');
    foreach ([AccessRecordEvent::CREATED, AccessRecordEvent::DELETED] as $name) {
      $dispatcher->addListener($name, function (AccessRecordEvent $event) use ($name): void {
        $this->events[] = [$name, $event->record->getKind(), $event->uri];
      });
    }
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
   * @param array<string, mixed>|string|null $body
   *   A JSON document, or a raw body.
   * @param array<string, string> $headers
   *   Headers.
   * @param array<string, mixed> $claims
   *   Claims to add to or replace in the token.
   */
  private function as(?string $agent, string $method, string $uri, array|string|null $body = NULL, array $headers = [], array $claims = []): Response {
    if ($agent !== NULL) {
      $headers['Authorization'] = 'Bearer ' . $this->token($agent, $claims);
    }
    if (is_array($body)) {
      $headers += ['Content-Type' => 'application/lws+json'];
      $body = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    return $this->send($method, substr($uri, strlen(self::BASE)), $headers, $body);
  }

  /**
   * An access grant or request document.
   *
   * @param string $type
   *   AccessGrant or AccessRequest.
   * @param string $assignee
   *   The assignee of its one policy.
   * @param list<array<string, mixed>> $constraints
   *   The policy's constraints.
   *
   * @return array<string, mixed>
   *   The document.
   */
  private static function document(string $type, string $assignee = self::BOB, array $constraints = []): array {
    $policy = [
      'type' => ['AccessPolicy'],
      'action' => ['read'],
      'assignee' => $assignee,
      'target' => ['type' => 'StorageResource', 'value' => [self::SHARED]],
    ];
    if ($constraints !== []) {
      $policy['constraint'] = $constraints;
    }
    return [
      '@context' => ['https://www.w3.org/ns/lws/v1'],
      'type' => [$type],
      'storage' => self::STORAGE,
      'access' => [$policy],
    ];
  }

  /**
   * The IDs in an agent's listing of a service.
   *
   * @return list<string>
   *   The IDs.
   */
  private function listed(string $agent, string $uri): array {
    $response = $this->as($agent, 'GET', $uri);
    $this->assertSame(200, $response->getStatusCode());
    return array_column($this->json($response)['items'], 'id');
  }

  /**
   * Tests that the storage description advertises both services.
   */
  public function testDiscovery(): void {
    $services = $this->json($this->send('GET', '/lws/alice/'))['service'];
    $byType = array_column($services, NULL, 'type');
    $this->assertSame(self::REQUESTS, $byType['AccessRequestService']['serviceEndpoint']);
    $this->assertSame(self::GRANTS, $byType['AccessGrantService']['serviceEndpoint']);
    $this->assertSame(['https://www.w3.org/ns/lws#AccessProfile'], $byType['AccessGrantService']['conformsTo']);

    // Each service is a container.
    $response = $this->as(self::ALICE, 'GET', self::GRANTS);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('application/lws+json', $response->headers->get('Content-Type'));
    $this->assertContains('<https://www.w3.org/ns/lws#Container>; rel="type"', $response->headers->all('link'));
    $this->assertSame('GET, HEAD, POST, OPTIONS', $response->headers->get('Allow'));
    $this->assertSame([
      '@context' => 'https://www.w3.org/ns/lws/v1',
      'id' => self::GRANTS,
      'type' => 'Container',
      'totalItems' => 0,
      'items' => [],
    ], $this->json($response));
    // Without a token, the storage's challenge.
    $response = $this->as(NULL, 'GET', self::GRANTS);
    $this->assertSame(401, $response->getStatusCode());
    $this->assertStringContainsString('realm="' . self::STORAGE . '"', (string) $response->headers->get('WWW-Authenticate'));
    $this->assertSame(405, $this->as(self::ALICE, 'PUT', self::GRANTS, '{}')->getStatusCode());
    $this->assertSame(404, $this->as(self::ALICE, 'GET', self::STORAGE . 'access/other/')->getStatusCode());
    $this->assertSame(404, $this->as(self::ALICE, 'GET', self::GRANTS . '00000000-0000-4000-8000-000000000000')->getStatusCode());
  }

  /**
   * Tests granting, reading the grant, and revoking it.
   */
  public function testGrant(): void {
    $this->assertSame(403, $this->as(self::BOB, 'GET', self::SHARED . 'a.txt')->getStatusCode());
    $response = $this->as(self::ALICE, 'POST', self::GRANTS, self::document('AccessGrant') + ['urn:example:note' => 'kept']);
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    $grant = (string) $response->headers->get('Location');
    $this->assertMatchesRegularExpression('#^' . preg_quote(self::GRANTS, '#') . '[0-9a-f-]{36}$#', $grant);

    // It takes effect at once.
    $this->assertSame(200, $this->as(self::BOB, 'GET', self::SHARED . 'a.txt')->getStatusCode());
    $this->assertSame(403, $this->as(self::CAROL, 'GET', self::SHARED . 'a.txt')->getStatusCode());

    // It reads back as submitted, with its id.
    $response = $this->as(self::ALICE, 'GET', $grant);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('application/lws+json', $response->headers->get('Content-Type'));
    $this->assertSame(['id' => $grant] + self::document('AccessGrant') + ['urn:example:note' => 'kept'], $this->json($response));
    $this->assertSame('GET, HEAD, DELETE, OPTIONS', $response->headers->get('Allow'));
    $this->assertNotNull($response->getEtag());

    // Bob, whom it names, sees it; carol does not.
    $this->assertSame(200, $this->as(self::BOB, 'GET', $grant)->getStatusCode());
    $this->assertSame(403, $this->as(self::CAROL, 'GET', $grant)->getStatusCode());
    $this->assertSame([$grant], $this->listed(self::ALICE, self::GRANTS));
    $this->assertSame([$grant], $this->listed(self::BOB, self::GRANTS));
    $this->assertSame([], $this->listed(self::CAROL, self::GRANTS));

    // Only a controller revokes it, and then the access is gone.
    $this->assertSame(403, $this->as(self::BOB, 'DELETE', $grant)->getStatusCode());
    $this->assertSame(204, $this->as(self::ALICE, 'DELETE', $grant)->getStatusCode());
    $this->assertSame(403, $this->as(self::BOB, 'GET', self::SHARED . 'a.txt')->getStatusCode());
    $this->assertSame(404, $this->as(self::ALICE, 'GET', $grant)->getStatusCode());
    $storage = $this->container->get('lws_storage.storage_registry')->get('alice');
    $this->assertNotNull($storage);
    $this->assertSame([], $this->container->get('lws_authz.policy_store')->forStorage($storage->id));
    $this->assertSame([
      [AccessRecordEvent::CREATED, 'grant', $grant],
      [AccessRecordEvent::DELETED, 'grant', $grant],
    ], $this->events);
  }

  /**
   * Tests a grant that ends after 2038, beyond a 32-bit integer.
   *
   * MySQL and MariaDB refused its policy for a not_after out of range.
   */
  public function testGrantEndingAfter2038(): void {
    $until = ['leftOperand' => 'dateTime', 'operator' => 'lteq', 'rightOperand' => '2999-12-31T23:59:59Z'];
    $response = $this->as(self::ALICE, 'POST', self::GRANTS, self::document('AccessGrant', self::BOB, [$until]));
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame(200, $this->as(self::BOB, 'GET', self::SHARED . 'a.txt')->getStatusCode());
    $policies = $this->container->get('entity_type.manager')->getStorage('lws_policy')->loadMultiple();
    $this->assertCount(1, $policies);
    $policy = reset($policies);
    $this->assertInstanceOf(LwsPolicyInterface::class, $policy);
    // 2999-12-31T23:59:59Z.
    $this->assertSame(32503679999, $policy->getNotAfter());
  }

  /**
   * Tests who may grant, and what is refused.
   */
  public function testGrantRefused(): void {
    $this->assertSame(401, $this->as(NULL, 'POST', self::GRANTS, self::document('AccessGrant'))->getStatusCode());
    $this->assertSame(403, $this->as(self::BOB, 'POST', self::GRANTS, self::document('AccessGrant'))->getStatusCode());

    $document = self::document('AccessGrant');
    $policy = $document['access'][0];
    $invalid = [
      'no type' => array_diff_key($document, ['type' => 1]),
      'a request\'s type' => ['type' => ['AccessRequest']] + $document,
      'no storage' => array_diff_key($document, ['storage' => 1]),
      'another storage' => ['storage' => self::BASE . '/lws/bob/'] + $document,
      'no access' => array_diff_key($document, ['access' => 1]),
      'empty access' => ['access' => []] + $document,
      'a policy without its type' => ['access' => [array_diff_key($policy, ['type' => 1])]] + $document,
      'a policy without action' => ['access' => [array_diff_key($policy, ['action' => 1])]] + $document,
      'a policy without assignee' => ['access' => [array_diff_key($policy, ['assignee' => 1])]] + $document,
      'a target that is not an object' => ['access' => [['target' => self::SHARED] + $policy]] + $document,
      'a target outside the storage' => [
        'access' => [['target' => ['type' => 'StorageResource', 'value' => ['https://elsewhere.example/']]] + $policy],
      ] + $document,
      'an inbox that is not a URL' => ['inbox' => 'not a uri'] + $document,
      'a context without LWS' => ['@context' => ['https://www.w3.org/ns/activitystreams']] + $document,
    ];
    foreach ($invalid as $name => $body) {
      $response = $this->as(self::ALICE, 'POST', self::GRANTS, $body);
      $this->assertSame(422, $response->getStatusCode(), $name);
      $this->assertSame('application/problem+json', $response->headers->get('Content-Type'), $name);
    }
    $this->assertSame(400, $this->as(self::ALICE, 'POST', self::GRANTS, '{', ['Content-Type' => 'application/lws+json'])->getStatusCode());
    $this->assertSame(415, $this->as(self::ALICE, 'POST', self::GRANTS, '{}', ['Content-Type' => 'text/plain'])->getStatusCode());
    $this->assertSame(413, $this->as(self::ALICE, 'POST', self::GRANTS, str_repeat(' ', 70000) . '{}', ['Content-Type' => 'application/lws+json'])->getStatusCode());
    // None of them granted anything.
    $this->assertSame(403, $this->as(self::BOB, 'GET', self::SHARED . 'a.txt')->getStatusCode());
    $this->assertSame([], $this->listed(self::ALICE, self::GRANTS));

    // Without an @context, it is given one.
    $response = $this->as(self::ALICE, 'POST', self::GRANTS, array_diff_key($document, ['@context' => 1]));
    $this->assertSame(201, $response->getStatusCode());
    $read = $this->json($this->as(self::ALICE, 'GET', (string) $response->headers->get('Location')));
    $this->assertSame(['https://www.w3.org/ns/lws/v1'], $read['@context']);
  }

  /**
   * Tests that a grant limited to another client is withheld from the agent.
   */
  public function testClientConstrainedGrant(): void {
    $other = [['leftOperand' => 'client', 'operator' => 'eq', 'rightOperand' => 'https://other.example/app']];
    $grant = (string) $this->as(self::ALICE, 'POST', self::GRANTS, self::document('AccessGrant', self::BOB, $other))->headers->get('Location');
    $this->assertSame(403, $this->as(self::BOB, 'GET', $grant)->getStatusCode());
    $this->assertSame([], $this->listed(self::BOB, self::GRANTS));
    $this->assertSame(403, $this->as(self::BOB, 'GET', self::SHARED . 'a.txt')->getStatusCode());
    // Through that client, bob sees it, and may read.
    $claims = ['client_id' => 'https://other.example/app'];
    $this->assertSame(200, $this->as(self::BOB, 'GET', $grant, NULL, [], $claims)->getStatusCode());
    $this->assertSame(200, $this->as(self::BOB, 'GET', self::SHARED . 'a.txt', NULL, [], $claims)->getStatusCode());
  }

  /**
   * Tests requests: submitting, seeing, cancelling.
   */
  public function testRequest(): void {
    $this->assertSame(401, $this->as(NULL, 'POST', self::REQUESTS, self::document('AccessRequest'))->getStatusCode());
    // Only for oneself.
    $response = $this->as(self::BOB, 'POST', self::REQUESTS, self::document('AccessRequest', self::CAROL));
    $this->assertSame(403, $response->getStatusCode());
    $response = $this->as(self::BOB, 'POST', self::REQUESTS, ['inbox' => 'https://id.example/bob/inbox/'] + self::document('AccessRequest'));
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    $request = (string) $response->headers->get('Location');
    $this->assertStringStartsWith(self::REQUESTS, $request);
    // A request changes no access.
    $this->assertSame(403, $this->as(self::BOB, 'GET', self::SHARED . 'a.txt')->getStatusCode());

    $this->assertSame('AccessRequest', $this->json($this->as(self::ALICE, 'GET', $request))['type'][0]);
    $this->assertSame(200, $this->as(self::BOB, 'GET', $request)->getStatusCode());
    $this->assertSame(403, $this->as(self::CAROL, 'GET', $request)->getStatusCode());
    $this->assertSame([$request], $this->listed(self::ALICE, self::REQUESTS));
    $this->assertSame([$request], $this->listed(self::BOB, self::REQUESTS));
    $this->assertSame([], $this->listed(self::CAROL, self::REQUESTS));
    // Carol cannot cancel it; bob can.
    $this->assertSame(403, $this->as(self::CAROL, 'DELETE', $request)->getStatusCode());
    $this->assertSame(204, $this->as(self::BOB, 'DELETE', $request)->getStatusCode());
    $this->assertSame([], $this->listed(self::ALICE, self::REQUESTS));
    $this->assertSame([
      [AccessRecordEvent::CREATED, 'request', $request],
      [AccessRecordEvent::DELETED, 'request', $request],
    ], $this->events);

    // Requests are rate-limited per agent.
    $this->config('lws_authz.settings')->set('rate_limits.access_requests', 1)->save();
    $this->assertSame(429, $this->as(self::BOB, 'POST', self::REQUESTS, self::document('AccessRequest'))->getStatusCode());
  }

  /**
   * Tests approving and denying requests, and revoking grants, in the UI.
   */
  public function testApproval(): void {
    $inbox = ['inbox' => 'https://id.example/bob/inbox/'];
    $response = $this->as(self::BOB, 'POST', self::REQUESTS, $inbox + self::document('AccessRequest'));
    $this->assertSame(201, $response->getStatusCode());
    $records = $this->container->get('lws_authz.access_records');
    $storage = $this->loadStorage('alice');
    $pending = $records->pending((int) $storage->id());
    $this->assertCount(1, $pending);

    $formState = (new FormState())->setValues(['confirm' => 1, 'op' => 'Approve']);
    $this->container->get('form_builder')->submitForm(AccessRequestForm::class, $formState, $storage, $pending[0], 'approve');
    $this->assertSame([], $formState->getErrors());
    $this->assertSame([], $records->pending((int) $storage->id()));
    $this->assertSame(200, $this->as(self::BOB, 'GET', self::SHARED . 'a.txt')->getStatusCode());
    // The grant names the request's inbox, so bob hears of it.
    $grants = $this->listed(self::BOB, self::GRANTS);
    $this->assertCount(1, $grants);
    $grant = $this->json($this->as(self::BOB, 'GET', $grants[0]));
    $this->assertSame('https://id.example/bob/inbox/', $grant['inbox']);
    $this->assertSame(['AccessGrant'], $grant['type']);
    $created = array_values(array_filter($this->events, static fn (array $event): bool => $event[0] === AccessRecordEvent::CREATED && $event[1] === 'grant'));
    $this->assertSame($grants[0], $created[0][2]);

    // Removing one of its policies revokes the grant.
    $policies = $this->container->get('lws_authz.policy_store');
    $policy = current($policies->forStorage((int) $storage->id()));
    $this->assertNotFalse($policy);
    $formState = (new FormState())->setValues(['confirm' => 1, 'op' => 'Remove']);
    $this->container->get('form_builder')->submitForm(PolicyDeleteForm::class, $formState, $storage, $policy);
    $this->assertSame([], $this->listed(self::ALICE, self::GRANTS));
    $this->assertSame(403, $this->as(self::BOB, 'GET', self::SHARED . 'a.txt')->getStatusCode());

    // Denying deletes the request, and grants nothing.
    $this->as(self::BOB, 'POST', self::REQUESTS, self::document('AccessRequest'));
    $pending = $records->pending((int) $storage->id());
    $this->assertSame(LwsAccessRecordInterface::REQUEST, $pending[0]->getKind());
    $formState = (new FormState())->setValues(['confirm' => 1, 'op' => 'Deny']);
    $this->container->get('form_builder')->submitForm(AccessRequestForm::class, $formState, $storage, $pending[0], 'deny');
    $this->assertSame([], $records->pending((int) $storage->id()));
    $this->assertSame([], $this->listed(self::ALICE, self::GRANTS));
    $this->assertSame(403, $this->as(self::BOB, 'GET', self::SHARED . 'a.txt')->getStatusCode());

    // A deleted storage takes its requests and grants with it.
    $this->as(self::ALICE, 'POST', self::GRANTS, self::document('AccessGrant'));
    $this->as(self::BOB, 'POST', self::REQUESTS, self::document('AccessRequest'));
    $storage->delete();
    $this->assertSame([], $this->container->get('entity_type.manager')->getStorage('lws_access')->loadMultiple());
  }

}
