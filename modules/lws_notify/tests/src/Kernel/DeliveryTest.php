<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_notify\Kernel;

use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\lws_notify\Delivery\Deliverer;
use Drupal\lws_notify\Entity\LwsSubscriptionInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests delivering notifications to webhook inboxes.
 */
#[Group('lws')]
#[RunTestsInSeparateProcesses]
final class DeliveryTest extends NotifyKernelTestBase {

  /**
   * An RFC 3339 time in UTC.
   */
  private const PUBLISHED = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->createContainer('root/', 'notes');
  }

  /**
   * Subscribes, and returns the subscription.
   *
   * @param string $agent
   *   The agent.
   * @param list<string> $topics
   *   The topics, as paths under the storage URI.
   * @param string $inbox
   *   The inbox.
   */
  private function subscription(string $agent, array $topics, string $inbox = self::INBOX): LwsSubscriptionInterface {
    $response = $this->subscribe($agent, $topics, ['inbox' => $inbox]);
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    $storage = $this->container->get('lws_storage.storage_registry')->get('alice');
    $this->assertNotNull($storage);
    $subscription = $this->container->get('lws_notify.subscriptions')->load($storage, basename((string) $response->headers->get('Location')));
    $this->assertNotNull($subscription);
    return $subscription;
  }

  /**
   * Reloads a subscription.
   */
  private function reload(LwsSubscriptionInterface $subscription): LwsSubscriptionInterface {
    $reloaded = $this->container->get('entity_type.manager')->getStorage('lws_subscription')->loadUnchanged((int) $subscription->id());
    $this->assertInstanceOf(LwsSubscriptionInterface::class, $reloaded);
    return $reloaded;
  }

  /**
   * Claims the next item of the delivery queue.
   *
   * @return array<string, mixed>
   *   Its data.
   */
  private function claim(): array {
    $item = $this->container->get('queue')->get(Deliverer::QUEUE)->claimItem();
    $this->assertIsObject($item);
    $this->assertTrue(property_exists($item, 'data') && is_array($item->data));
    return $item->data;
  }

  /**
   * The object of a delivery's first activity.
   *
   * @param array{url: string, headers: array<string, string>, body: string, status: int} $delivery
   *   The delivery.
   */
  private function objectId(array $delivery): string {
    return (string) $this->activities($delivery)[0]['object']['id'];
  }

  /**
   * Tests that Create, Update and Delete are delivered, signed.
   */
  public function testDelivered(): void {
    $this->subscription(self::ALICE, ['root/notes/']);
    $this->assertSame([], $this->deliveries());

    $uri = $this->createData('root/notes/', 'a.txt');
    $this->assertCount(1, $this->deliveries());
    $delivery = $this->deliveries()[0];
    $this->assertSame(self::INBOX, $delivery['url']);
    $this->assertSame('application/lws+json', $delivery['headers']['content-type']);
    $notification = json_decode($delivery['body'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame(['https://www.w3.org/ns/lws/v1', 'https://www.w3.org/ns/activitystreams'], $notification['@context']);
    $this->assertSame('Notification', $notification['type']);
    $this->assertSame(self::STORAGE, $notification['storage']);
    // One activity is sent as an object.
    $activity = $notification['activity'];
    $this->assertMatchesRegularExpression('/^urn:uuid:[0-9a-f-]{36}$/', $activity['id']);
    $this->assertSame(['Create'], $activity['type']);
    $this->assertSame(['id' => $uri, 'type' => ['DataResource']], $activity['object']);
    $this->assertSame(self::STORAGE . 'root/notes/', $activity['target']);
    $this->assertMatchesRegularExpression(self::PUBLISHED, $activity['published']);
    // Who made the change is withheld by default.
    $this->assertArrayNotHasKey('actor', $activity);

    // Signed with the key the storage description publishes.
    $verified = $this->verify($delivery);
    $this->assertSame(self::STORAGE, $verified->storage);
    $this->assertSame('ecdsa-p256-sha256', $verified->algorithm);
    $this->assertStringStartsWith(self::STORAGE . '#', $verified->keyId);
    $this->assertSame(['Create'], $verified->notification->activities[0]->types);

    $path = (string) parse_url($uri, PHP_URL_PATH);
    $put = $this->as(self::ALICE, 'PUT', $path, ['Content-Type' => 'text/plain'], 'changed');
    $this->assertContains($put->getStatusCode(), [200, 204]);
    $update = $this->activities($this->deliveries()[1]);
    $this->assertSame(['Update'], $update[0]['type']);
    $this->assertSame($uri, $update[0]['object']['id']);
    $this->assertArrayNotHasKey('target', $update[0]);
    $this->verify($this->deliveries()[1]);

    $this->assertSame(204, $this->as(self::ALICE, 'DELETE', $path)->getStatusCode());
    $delete = $this->activities($this->deliveries()[2]);
    $this->assertSame(['Delete'], $delete[0]['type']);
    $this->assertSame(['id' => $uri, 'type' => ['DataResource']], $delete[0]['object']);
    $this->assertSame(self::STORAGE . 'root/notes/', $delete[0]['origin']);
    $this->assertCount(3, $this->deliveries());
  }

  /**
   * Tests that a change to a resource's links is an Update with its types.
   */
  public function testMetadataUpdate(): void {
    $uri = $this->createData('root/notes/', 'a.txt');
    $this->subscription(self::ALICE, ['root/notes/a.txt']);
    $head = $this->as(self::ALICE, 'HEAD', (string) parse_url($uri, PHP_URL_PATH));
    $this->assertSame(1, preg_match('/<([^>]+)>; rel="linkset"/', implode(', ', $head->headers->all('Link')), $matches));
    $linkset = (string) parse_url($matches[1], PHP_URL_PATH);
    $document = ['linkset' => [['anchor' => $uri, 'type' => [['href' => 'https://schema.example/Note']]]]];
    $response = $this->as(self::ALICE, 'PUT', $linkset, ['Content-Type' => 'application/linkset+json'], json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $this->assertContains($response->getStatusCode(), [200, 204], (string) $response->getContent());
    $this->assertCount(1, $this->deliveries());
    $activity = $this->activities($this->deliveries()[0])[0];
    $this->assertSame(['Update'], $activity['type']);
    $this->assertSame(['DataResource', 'https://schema.example/Note'], $activity['object']['type']);
  }

  /**
   * Tests that the activities of one request go in one notification.
   */
  public function testBatched(): void {
    $box = $this->createContainer('root/notes/', 'box');
    $inner = $this->createData('root/notes/box/', 'x.txt');
    $this->subscription(self::ALICE, ['root/']);
    $this->assertSame(204, $this->as(self::ALICE, 'DELETE', (string) parse_url($box, PHP_URL_PATH), ['Depth' => 'infinity'])->getStatusCode());
    $this->assertCount(1, $this->deliveries());
    $notification = json_decode($this->deliveries()[0]['body'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertTrue(array_is_list($notification['activity']));
    $deleted = [];
    foreach ($notification['activity'] as $activity) {
      $this->assertSame(['Delete'], $activity['type']);
      $deleted[$activity['object']['id']] = [$activity['object']['type'], $activity['origin']];
    }
    $this->assertSame([
      $inner => [['DataResource'], $box],
      $box => [['Container'], self::STORAGE . 'root/notes/'],
    ], $deleted);
    $this->verify($this->deliveries()[0]);
  }

  /**
   * Tests what a subscription covers (LWS Core §10.3.2).
   */
  public function testScope(): void {
    $a = $this->createData('root/notes/', 'a.txt');
    $this->subscription(self::ALICE, ['root/notes/a.txt'], 'https://inbox.example/a');
    $this->subscription(self::ALICE, ['root/notes/'], 'https://inbox.example/notes');
    // A data resource's subscription hears nothing of its siblings.
    $b = $this->createData('root/notes/', 'b.txt');
    $this->assertSame([], $this->deliveriesTo('https://inbox.example/a'));
    // A container's hears of everything in it, at any depth.
    $this->createContainer('root/notes/', 'sub');
    $deep = $this->createData('root/notes/sub/', 'deep.txt');
    $ids = array_map($this->objectId(...), $this->deliveriesTo('https://inbox.example/notes'));
    $this->assertSame([$b, self::STORAGE . 'root/notes/sub/', $deep], $ids);
    // Not of the container above it, or a sibling container.
    $this->createContainer('root/', 'other');
    $this->assertCount(3, $this->deliveriesTo('https://inbox.example/notes'));
    $this->as(self::ALICE, 'PUT', (string) parse_url($a, PHP_URL_PATH), ['Content-Type' => 'text/plain'], 'changed');
    $this->assertCount(1, $this->deliveriesTo('https://inbox.example/a'));
    $this->assertCount(4, $this->deliveriesTo('https://inbox.example/notes'));
  }

  /**
   * Tests that a subscriber hears only of what it may read, when it changes.
   */
  public function testAuthorizedWhenMade(): void {
    // Bob may read containers in shared/, but not the data resources.
    $this->createContainer('root/', 'shared');
    $policy = $this->letRead(self::BOB, ['root/shared/'], 'Container');
    $this->subscription(self::BOB, ['root/shared/'], 'https://inbox.example/bob');
    $this->subscription(self::ALICE, ['root/shared/']);
    $this->createData('root/shared/', 'secret.txt');
    $this->createContainer('root/shared/', 'open');
    $ids = array_map($this->objectId(...), $this->deliveriesTo('https://inbox.example/bob'));
    $this->assertSame([self::STORAGE . 'root/shared/open/'], $ids);
    $this->assertCount(2, $this->deliveriesTo());

    // Once the access is gone, so are the notifications; the subscription
    // stays.
    $this->container->get('entity_type.manager')->getStorage('lws_policy')->load($policy)?->delete();
    $this->createContainer('root/shared/', 'later');
    $this->assertCount(1, $this->deliveriesTo('https://inbox.example/bob'));
    $this->assertCount(3, $this->deliveriesTo());
  }

  /**
   * Tests that activities name the actor when the site says so.
   */
  public function testActor(): void {
    $this->config('lws_notify.settings')->set('include_actor', TRUE)->save();
    $this->subscription(self::ALICE, ['root/notes/']);
    $this->createData('root/notes/', 'a.txt');
    $this->assertSame(self::ALICE, $this->activities($this->deliveries()[0])[0]['actor']);
  }

  /**
   * Tests a retry at the end of the request.
   */
  public function testRetriedAtOnce(): void {
    $subscription = $this->subscription(self::ALICE, ['root/notes/']);
    $this->answers[self::INBOX] = [503];
    $this->createData('root/notes/', 'a.txt');
    $this->assertSame([503, 202], array_column($this->deliveries(), 'status'));
    $this->assertSame($this->activities($this->deliveries()[0]), $this->activities($this->deliveries()[1]));
    // Each attempt is signed afresh.
    $this->verify($this->deliveries()[1]);
    $subscription = $this->reload($subscription);
    $this->assertSame(0, $subscription->getFailures());
    $this->assertSame(202, (int) $subscription->get('last_status')->value);
    $this->assertTrue($subscription->isActive());
  }

  /**
   * Tests later retries, from the queue.
   */
  public function testRetriedLater(): void {
    $this->createContainer('root/', 'shared');
    $this->letRead(self::BOB, ['root/shared/']);
    $subscription = $this->subscription(self::BOB, ['root/shared/']);
    $this->answers[self::INBOX] = [503, 0];
    $this->createData('root/shared/', 'a.txt');
    $this->assertSame([503, 0], array_column($this->deliveries(), 'status'));

    $this->assertSame(1, $this->container->get('queue')->get(Deliverer::QUEUE)->numberOfItems());
    $item = $this->claim();
    $this->assertSame(3, $item['attempt']);
    $this->assertEqualsWithDelta(time() + 60, $item['not_before'], 5);
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance(Deliverer::QUEUE);
    try {
      $worker->processItem($item);
      $this->fail('A retry was made before its time.');
    }
    catch (DelayedRequeueException $e) {
      $this->assertEqualsWithDelta(60, $e->getDelay(), 5);
    }
    $this->assertCount(2, $this->deliveries());

    // In time, it is delivered.
    $due = ['not_before' => 0] + $item;
    $worker->processItem($due);
    $this->assertSame([503, 0, 202], array_column($this->deliveries(), 'status'));
    $this->assertSame(202, (int) $this->reload($subscription)->get('last_status')->value);

    // A retry delivers nothing the subscriber can no longer read.
    $policies = $this->container->get('entity_type.manager')->getStorage('lws_policy');
    $policies->delete($policies->loadMultiple());
    $worker->processItem($due);
    $this->assertCount(3, $this->deliveries());
  }

  /**
   * Tests that a subscription whose inbox is gone is deactivated.
   */
  public function testGone(): void {
    $subscription = $this->subscription(self::ALICE, ['root/notes/']);
    $this->answers[self::INBOX] = [410];
    $this->createData('root/notes/', 'a.txt');
    $this->assertSame([410], array_column($this->deliveries(), 'status'));
    $this->assertFalse($this->reload($subscription)->isActive());
    $storage = $this->container->get('lws_storage.storage_registry')->get('alice');
    $this->assertNotNull($storage);
    $path = (string) parse_url($this->container->get('lws_notify.subscriptions')->uri($storage, $subscription), PHP_URL_PATH);
    $this->assertFalse($this->json($this->as(self::ALICE, 'GET', $path))['active']);
    $this->assertSame(0, $this->json($this->as(self::ALICE, 'GET', '/lws/alice/notifications/'))['totalItems']);
    $this->createData('root/notes/', 'b.txt');
    $this->assertCount(1, $this->deliveries());
  }

  /**
   * Tests that failures in a row deactivate a subscription.
   */
  public function testFailures(): void {
    $this->config('lws_notify.settings')->set('delivery.max_failures', 3)->set('delivery.retry_delays', [])->save();
    $subscription = $this->subscription(self::ALICE, ['root/notes/']);
    // A 404 is not retried; a 503 is not either, without retry delays.
    $this->answers[self::INBOX] = [404, 503, 202, 404, 404, 404];
    foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $name) {
      $this->createData('root/notes/', $name);
    }
    // The success reset the count; three failures after it ended it.
    $this->assertSame([404, 503, 202, 404, 404, 404], array_column($this->deliveries(), 'status'));
    $subscription = $this->reload($subscription);
    $this->assertFalse($subscription->isActive());
    $this->assertSame(3, $subscription->getFailures());
    $this->createData('root/notes/', 'g');
    $this->assertCount(6, $this->deliveries());
    $this->assertSame(0, $this->container->get('queue')->get(Deliverer::QUEUE)->numberOfItems());
  }

  /**
   * Tests that an inbox the guard refuses at delivery time is not posted to.
   */
  public function testRefusedInbox(): void {
    $this->subscription(self::ALICE, ['root/notes/'], 'https://inbox.example/fine');
    // As if the name now resolved to a private address.
    $subscriptions = $this->container->get('entity_type.manager')->getStorage('lws_subscription');
    foreach ($subscriptions->loadMultiple() as $subscription) {
      $this->assertInstanceOf(LwsSubscriptionInterface::class, $subscription);
      $subscription->set('inbox', 'https://internal.example/hooks')->save();
    }
    $this->createData('root/notes/', 'a.txt');
    $this->assertSame([], $this->deliveries());
    $this->assertSame(1, $this->container->get('queue')->get(Deliverer::QUEUE)->numberOfItems());
  }

  /**
   * Tests delivering everything on cron, when the site says so.
   */
  public function testOnCron(): void {
    $this->config('lws_notify.settings')->set('delivery.inline', FALSE)->save();
    $this->subscription(self::ALICE, ['root/notes/']);
    $this->createData('root/notes/', 'a.txt');
    $this->assertSame([], $this->deliveries());
    $item = $this->claim();
    $this->container->get('plugin.manager.queue_worker')->createInstance(Deliverer::QUEUE)->processItem($item);
    $this->assertSame(['Create'], $this->activities($this->deliveries()[0])[0]['type']);
  }

  /**
   * Tests that a delivery is sent unsigned when there is no key.
   */
  public function testWithoutKey(): void {
    $this->errorsExpected = TRUE;
    $this->setSetting('lws_notify_key_directory', '/proc/no-such-directory');
    $this->subscription(self::ALICE, ['root/notes/']);
    $this->createData('root/notes/', 'a.txt');
    $this->assertCount(1, $this->deliveries());
    $this->assertArrayNotHasKey('signature', $this->deliveries()[0]['headers']);
    $description = $this->json($this->send('GET', '/lws/alice/', ['Accept' => 'application/lws+cid']));
    $this->assertArrayNotHasKey('verificationMethod', $description);
    $this->assertStringContainsString('Sending a notification unsigned', implode("\n", $this->logged));
  }

  /**
   * Tests notifications of access grants and requests (LWS Core §11.6).
   */
  public function testAccessRecords(): void {
    $grant = [
      '@context' => ['https://www.w3.org/ns/lws/v1'],
      'type' => ['AccessGrant'],
      'storage' => self::STORAGE,
      'inbox' => 'https://inbox.example/bob',
      'access' => [
        [
          'type' => ['AccessPolicy'],
          'action' => ['read'],
          'assignee' => self::BOB,
          'target' => ['type' => 'StorageResource', 'value' => [self::STORAGE . 'root/notes/']],
        ],
      ],
    ];
    $response = $this->as(self::ALICE, 'POST', '/lws/alice/access/grants/', ['Content-Type' => 'application/lws+json'], json_encode($grant, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    $uri = (string) $response->headers->get('Location');
    $deliveries = $this->deliveriesTo('https://inbox.example/bob');
    $this->assertCount(1, $deliveries);
    $activity = $this->activities($deliveries[0])[0];
    $this->assertSame(['Create'], $activity['type']);
    $this->assertSame(['id' => $uri, 'type' => ['DataResource', 'AccessGrant']], $activity['object']);
    $this->assertSame(self::STORAGE . 'access/grants/', $activity['target']);
    $this->verify($deliveries[0]);

    $this->assertSame(204, $this->as(self::ALICE, 'DELETE', (string) parse_url($uri, PHP_URL_PATH))->getStatusCode());
    $activity = $this->activities($this->deliveriesTo('https://inbox.example/bob')[1])[0];
    $this->assertSame(['Delete'], $activity['type']);
    $this->assertSame(self::STORAGE . 'access/grants/', $activity['origin']);

    // Approving a request tells its inbox of the grant, and that the request
    // is settled, in one notification.
    $request = ['type' => ['AccessRequest'], 'inbox' => 'https://inbox.example/request'] + $grant;
    $response = $this->as(self::BOB, 'POST', '/lws/alice/access/requests/', ['Content-Type' => 'application/lws+json'], json_encode($request, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame(['Create'], $this->activities($this->deliveriesTo('https://inbox.example/request')[0])[0]['type']);
    $storage = $this->container->get('lws_storage.storage_registry')->get('alice');
    $this->assertNotNull($storage);
    $records = $this->container->get('lws_authz.access_records');
    $pending = $records->pending($storage->id);
    $records->approve($storage, $pending[0], 0);
    $this->container->get('lws_notify.notifier')->flush();
    $settled = $this->activities($this->deliveriesTo('https://inbox.example/request')[1]);
    $this->assertSame([['Create'], ['Delete']], array_column($settled, 'type'));
    $this->assertSame(['DataResource', 'AccessGrant'], $settled[0]['object']['type']);
    $this->assertSame(['DataResource', 'AccessRequest'], $settled[1]['object']['type']);
  }

}
