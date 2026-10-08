<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_notify\Kernel;

use Drupal\lws_notify\Controller\NotificationController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the notification service: discovery and subscriptions.
 */
#[Group('lws')]
#[RunTestsInSeparateProcesses]
final class SubscriptionServiceTest extends NotifyKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->createContainer('root/', 'notes');
    $this->createData('root/notes/', 'a.txt');
  }

  /**
   * Tests the service and the webhook key in the storage description.
   */
  public function testDescription(): void {
    $description = $this->json($this->send('GET', '/lws/alice/', ['Accept' => 'application/lws+cid']));
    $this->assertContains([
      'type' => 'NotificationService',
      'serviceEndpoint' => self::STORAGE . 'notifications/',
      'subscriptionType' => ['WebhookSubscription'],
    ], $description['service']);
    $this->assertCount(1, $description['verificationMethod']);
    $method = $description['verificationMethod'][0];
    $kid = $method['publicKeyJwk']['kid'];
    $this->assertSame(self::STORAGE . '#' . $kid, $method['id']);
    $this->assertSame('JsonWebKey', $method['type']);
    $this->assertSame(self::STORAGE, $method['controller']);
    $jwk = $method['publicKeyJwk'];
    $this->assertSame(['EC', 'P-256', 'ES256'], [$jwk['kty'], $jwk['crv'], $jwk['alg']]);
    $this->assertArrayNotHasKey('d', $method['publicKeyJwk']);
    $this->assertSame([$method['id']], $description['authentication']);

    // After a rotation, the new key signs and the old one stays published.
    $new = $this->container->get('lws_notify.signing_keys')->rotate();
    $description = $this->json($this->send('GET', '/lws/alice/', ['Accept' => 'application/lws+cid']));
    $this->assertSame([self::STORAGE . '#' . $new, self::STORAGE . '#' . $kid], $description['authentication']);
  }

  /**
   * Tests subscribing, and the subscription's representation.
   */
  public function testSubscribe(): void {
    $response = $this->subscribe(self::ALICE, ['root/notes/', 'root/notes/a.txt', 'root/notes/']);
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame('application/lws+json', $response->headers->get('Content-Type'));
    $uri = (string) $response->headers->get('Location');
    $this->assertMatchesRegularExpression('#^' . preg_quote(self::STORAGE, '#') . 'notifications/[0-9a-f-]{36}$#', $uri);
    $body = $this->json($response);
    $this->assertSame('WebhookSubscription', $body['type']);
    $this->assertSame($uri, $body['subscription']);
    // Thirty days, the longest a subscription lasts by default.
    $expires = strtotime($body['expires']);
    $this->assertEqualsWithDelta(time() + 2592000, $expires, 5);
    $this->assertSame([], $this->deliveries());

    $path = (string) parse_url($uri, PHP_URL_PATH);
    $read = $this->as(self::ALICE, 'GET', $path);
    $this->assertSame(200, $read->getStatusCode());
    $this->assertSame([
      '@context' => 'https://www.w3.org/ns/lws/v1',
      'id' => $uri,
      'type' => 'WebhookSubscription',
      'subscription' => $uri,
      'topic' => [self::STORAGE . 'root/notes/', self::STORAGE . 'root/notes/a.txt'],
      'inbox' => self::INBOX,
      'expires' => $body['expires'],
      'active' => TRUE,
    ], $this->json($read));
    $this->assertSame('GET, HEAD, DELETE, OPTIONS', $read->headers->get('Allow'));
    $this->assertNotNull($read->headers->get('ETag'));
    $this->assertSame(200, $this->as(self::ALICE, 'HEAD', $path)->getStatusCode());

    // The listing is an LWS container of the agent's subscriptions.
    $listing = $this->as(self::ALICE, 'GET', '/lws/alice/notifications/');
    $this->assertSame(200, $listing->getStatusCode());
    $this->assertSame('application/lws+json', $listing->headers->get('Content-Type'));
    $this->assertSame('GET, HEAD, POST, OPTIONS', $listing->headers->get('Allow'));
    $this->assertSame('application/lws+json', $listing->headers->get('Accept-Post'));
    $this->assertStringContainsString('<https://www.w3.org/ns/lws#Container>; rel="type"', implode(', ', $listing->headers->all('Link')));
    $container = $this->json($listing);
    $this->assertSame(self::STORAGE . 'notifications/', $container['id']);
    $this->assertSame('Container', $container['type']);
    $this->assertSame(1, $container['totalItems']);
    $this->assertSame($uri, $container['items'][0]['id']);
    $this->assertSame(['DataResource', 'WebhookSubscription'], $container['items'][0]['type']);

    // Bob sees neither, and without a token there is a challenge.
    $this->letRead(self::BOB, ['root/notes/']);
    $this->assertProblem($this->as(self::BOB, 'GET', $path), 403, $uri);
    $this->assertSame(0, $this->json($this->as(self::BOB, 'GET', '/lws/alice/notifications/'))['totalItems']);
    $challenge = $this->as(NULL, 'GET', $path);
    $this->assertSame(401, $challenge->getStatusCode());
    $this->assertStringStartsWith('Bearer ', (string) $challenge->headers->get('WWW-Authenticate'));
    $this->assertSame(401, $this->as(NULL, 'GET', '/lws/alice/notifications/')->getStatusCode());
  }

  /**
   * Tests that the storage URI stands for its root container.
   */
  public function testStorageTopic(): void {
    $response = $this->subscribe(self::ALICE, ['']);
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    $read = $this->as(self::ALICE, 'GET', (string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));
    $this->assertSame([self::STORAGE . 'root/'], $this->json($read)['topic']);
  }

  /**
   * Tests the expiry a subscriber asks for.
   */
  public function testExpires(): void {
    $soon = gmdate('Y-m-d\TH:i:s\Z', time() + 3600);
    $this->assertSame($soon, $this->json($this->subscribe(self::ALICE, ['root/'], ['expires' => $soon]))['expires']);
    // Later than the site allows is shortened to its limit.
    $late = $this->json($this->subscribe(self::ALICE, ['root/'], ['expires' => '2999-01-01T00:00:00+02:00']))['expires'];
    $this->assertEqualsWithDelta(time() + 2592000, strtotime($late), 5);
    // Without a limit, it is kept, in UTC.
    $this->config('lws_notify.settings')->set('limits.lifetime', 0)->save();
    $this->assertSame('2998-12-31T22:00:00Z', $this->json($this->subscribe(self::ALICE, ['root/'], ['expires' => '2999-01-01T00:00:00+02:00']))['expires']);
    $response = $this->subscribe(self::ALICE, ['root/'], ['expires' => NULL]);
    $this->assertArrayNotHasKey('expires', $this->json($response));
  }

  /**
   * Requests the service refuses, and why.
   *
   * @return array<string, array{array<string, mixed>, string}>
   *   Members that replace the valid request's, and part of the problem's
   *   detail.
   */
  public static function invalidRequests(): array {
    return [
      'no type' => [['type' => NULL], 'needs a "type"'],
      'type not offered' => [['type' => 'WebSocketChannel2023'], 'offers WebhookSubscription subscriptions only'],
      'type not a string' => [['type' => ['WebhookSubscription']], 'needs a "type"'],
      'no topic' => [['topic' => NULL], 'needs a "topic"'],
      'empty topic' => [['topic' => []], 'needs a "topic"'],
      'topic not a list' => [['topic' => self::STORAGE . 'root/'], 'needs a "topic"'],
      'topic not a string' => [['topic' => [42]], 'Each topic is a URI string'],
      'topic elsewhere' => [['topic' => ['https://elsewhere.example/root/']], 'is not a resource of the storage'],
      'topic in another storage' => [['topic' => [self::BASE . '/lws/bob/root/']], 'is not a resource of the storage'],
      'topic a linkset' => [
        ['topic' => [self::STORAGE . 'meta/0b5c3a5e-6a3e-4c1f-9d2e-3f1a2b3c4d5e']],
        'is not a resource of the storage',
      ],
      'topic the notification service' => [
        ['topic' => [self::STORAGE . 'notifications/']],
        'is not a resource of the storage',
      ],
      'topic with a query' => [['topic' => [self::STORAGE . 'root/?page=2']], 'is not a resource of the storage'],
      'too many topics' => [
        ['topic' => array_map(static fn (int $i): string => self::STORAGE . 'root/' . $i, range(1, 33))],
        'at most 32 topics',
      ],
      'no inbox' => [['inbox' => NULL], 'needs an "inbox"'],
      'inbox not http' => [['inbox' => 'mailto:alice@example.com'], 'absolute http(s) URL'],
      'inbox relative' => [['inbox' => '/hooks'], 'absolute http(s) URL'],
      'inbox with user' => [['inbox' => 'https://alice:secret@inbox.example/'], 'without user information'],
      'inbox with fragment' => [['inbox' => 'https://inbox.example/#x'], 'or a fragment'],
      'inbox too long' => [['inbox' => 'https://inbox.example/' . str_repeat('a', 2048)], 'at most 2048'],
      'inbox private' => [['inbox' => 'https://internal.example/hooks'], 'delivers to HTTPS URLs of public hosts'],
      'inbox plain http' => [['inbox' => 'http://inbox.example/hooks'], 'delivers to HTTPS URLs of public hosts'],
      'expires malformed' => [['expires' => 'tomorrow'], 'RFC 3339'],
      'expires past' => [['expires' => '2020-01-01T00:00:00Z'], 'in the future'],
    ];
  }

  /**
   * Tests requests that are refused with 422.
   *
   * @param array<string, mixed> $replace
   *   Members to replace; NULL removes one.
   * @param string $detail
   *   Part of the problem's detail.
   */
  #[DataProvider('invalidRequests')]
  public function testInvalid(array $replace, string $detail): void {
    $request = [
      'type' => 'WebhookSubscription',
      'topic' => [self::STORAGE . 'root/'],
      'inbox' => self::INBOX,
    ];
    foreach ($replace as $member => $value) {
      if ($value === NULL) {
        unset($request[$member]);
      }
      else {
        $request[$member] = $value;
      }
    }
    $response = $this->as(self::ALICE, 'POST', '/lws/alice/notifications/', ['Content-Type' => 'application/lws+json'], json_encode($request, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $this->assertProblem($response, 422, self::STORAGE . 'notifications/');
    $this->assertStringContainsString($detail, $this->json($response)['detail']);
    // What a host resolves to is logged, never told.
    $this->assertStringNotContainsString('10.0.0.5', $this->json($response)['detail']);
    $this->assertSame(0, $this->json($this->as(self::ALICE, 'GET', '/lws/alice/notifications/'))['totalItems']);
  }

  /**
   * Tests refusals other than 422.
   */
  public function testRefused(): void {
    $service = self::STORAGE . 'notifications/';
    $valid = json_encode(['type' => 'WebhookSubscription', 'topic' => [self::STORAGE . 'root/'], 'inbox' => self::INBOX], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $this->assertSame(401, $this->as(NULL, 'POST', '/lws/alice/notifications/', ['Content-Type' => 'application/lws+json'], $valid)->getStatusCode());
    $this->assertProblem($this->as(self::ALICE, 'POST', '/lws/alice/notifications/', ['Content-Type' => 'text/turtle'], $valid), 415, $service);
    $this->assertProblem($this->as(self::ALICE, 'POST', '/lws/alice/notifications/', ['Content-Type' => 'application/lws+json'], '{"type":'), 400, $service);
    $this->assertProblem($this->as(self::ALICE, 'POST', '/lws/alice/notifications/', ['Content-Type' => 'application/lws+json'], str_repeat(' ', NotificationController::MAX_BYTES + 1)), 413, $service);

    // Bob may read root/notes/a.txt only: subscribing to more is refused.
    $this->letRead(self::BOB, ['root/notes/a.txt']);
    $this->assertSame(201, $this->subscribe(self::BOB, ['root/notes/a.txt'])->getStatusCode());
    $refused = $this->subscribe(self::BOB, ['root/notes/a.txt', 'root/notes/']);
    $this->assertProblem($refused, 403, $service);
    $this->assertStringContainsString('may not read ' . self::STORAGE . 'root/notes/', $this->json($refused)['detail']);
    // Whether a topic exists makes no difference.
    $this->assertProblem($this->subscribe(self::BOB, ['root/nothing']), 403, $service);
    $this->assertSame(201, $this->subscribe(self::ALICE, ['root/nothing'])->getStatusCode());

    // An agent holds a limited number of subscriptions at a storage.
    $this->config('lws_notify.settings')->set('limits.subscriptions_per_agent', 2)->save();
    $this->assertSame(201, $this->subscribe(self::BOB, ['root/notes/a.txt'])->getStatusCode());
    $limited = $this->subscribe(self::BOB, ['root/notes/a.txt']);
    $this->assertProblem($limited, 429, $service);
    $this->assertStringContainsString('may hold 2 subscriptions', $this->json($limited)['detail']);
  }

  /**
   * Tests cancelling subscriptions.
   */
  public function testCancel(): void {
    $this->letRead(self::BOB, ['root/notes/']);
    $mine = (string) parse_url((string) $this->subscribe(self::ALICE, ['root/'])->headers->get('Location'), PHP_URL_PATH);
    $bobs = (string) parse_url((string) $this->subscribe(self::BOB, ['root/notes/'])->headers->get('Location'), PHP_URL_PATH);
    $this->assertSame(403, $this->as(self::BOB, 'DELETE', $mine)->getStatusCode());
    // The storage's controller may see and cancel anyone's.
    $this->assertSame(200, $this->as(self::ALICE, 'GET', $bobs)->getStatusCode());
    $this->assertSame(204, $this->as(self::ALICE, 'DELETE', $bobs)->getStatusCode());
    $this->assertSame(404, $this->as(self::BOB, 'GET', $bobs)->getStatusCode());
    $this->assertSame(204, $this->as(self::ALICE, 'DELETE', $mine)->getStatusCode());
    $this->assertSame(404, $this->as(self::ALICE, 'DELETE', $mine)->getStatusCode());
    $this->assertSame(0, $this->json($this->as(self::ALICE, 'GET', '/lws/alice/notifications/'))['totalItems']);
    $this->assertSame(404, $this->as(self::ALICE, 'GET', '/lws/alice/notifications/0b5c3a5e-6a3e-4c1f-9d2e-3f1a2b3c4d5e')->getStatusCode());
  }

  /**
   * Tests that subscriptions end with their storage, and are tidied away.
   */
  public function testEnd(): void {
    $subscriptions = $this->container->get('lws_notify.subscriptions');
    $storage = $this->container->get('lws_storage.storage_registry')->get('alice');
    $this->assertNotNull($storage);
    $this->subscribe(self::ALICE, ['root/']);
    $expiring = $this->json($this->subscribe(self::ALICE, ['root/'], ['expires' => gmdate('Y-m-d\TH:i:s\Z', time() + 60)]));
    [, $total] = $subscriptions->page($storage, self::ALICE, 0, 10);
    $this->assertSame(2, $total);

    // An expired subscription is no longer listed, but shows that it ended,
    // until it is purged a week later.
    $entity = $subscriptions->load($storage, basename($expiring['subscription']));
    $this->assertNotNull($entity);
    $entity->set('expires', time() - 10)->save();
    $this->assertSame(1, $this->json($this->as(self::ALICE, 'GET', '/lws/alice/notifications/'))['totalItems']);
    $this->assertFalse($this->json($this->as(self::ALICE, 'GET', (string) parse_url($expiring['subscription'], PHP_URL_PATH)))['active']);
    $this->assertSame(0, $subscriptions->purge());
    $entity->set('expires', time() - 8 * 86400)->save();
    $this->container->get('module_handler')->invoke('lws_notify', 'cron');
    $this->assertNull($subscriptions->load($storage, basename($expiring['subscription'])));

    $this->container->get('entity_type.manager')->getStorage('lws_storage')->load($storage->id)?->delete();
    $this->assertSame([], $this->container->get('entity_type.manager')->getStorage('lws_subscription')->loadMultiple());
  }

}
