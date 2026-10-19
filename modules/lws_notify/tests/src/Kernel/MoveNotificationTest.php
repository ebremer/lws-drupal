<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_notify\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the notifications of moves (LWS Core §10.2.3, Moving Resources).
 *
 * The storage holds source/ and destination/, and source/ a.txt, which Alice
 * moves to destination/.
 */
#[Group('lws')]
#[RunTestsInSeparateProcesses]
final class MoveNotificationTest extends NotifyKernelTestBase {

  private const CAROL = 'https://carol.example/profile#me';

  private const DAVE = 'https://dave.example/profile#me';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->createContainer('root/', 'source');
    $this->createContainer('root/', 'destination');
    $this->createContainer('root/', 'elsewhere');
    $this->createData('root/source/', 'a.txt');
  }

  /**
   * Moves a resource, as Alice, by replacing the target of its "up" link.
   *
   * @param string $path
   *   The resource, as a path under the storage URI.
   * @param string $destination
   *   The container it moves to, as a path under the storage URI.
   */
  private function move(string $path, string $destination): Response {
    $linkset = NULL;
    foreach ($this->as(self::ALICE, 'HEAD', '/lws/alice/' . $path)->headers->all('link') as $link) {
      if (preg_match('/^<([^>]*)>;\s*rel="linkset"/', (string) $link, $match) === 1) {
        $linkset = $match[1];
      }
    }
    $this->assertNotNull($linkset);
    $patch = json_encode([
      ['op' => 'replace', 'path' => '/linkset/0/up/0/href', 'value' => self::STORAGE . $destination],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $response = $this->as(self::ALICE, 'PATCH', substr($linkset, strlen(self::BASE)), ['Content-Type' => 'application/json-patch+json'], $patch);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    return $response;
  }

  /**
   * The activities delivered to an inbox, of every delivery to it.
   *
   * @return list<array<string, mixed>>
   *   The activities, oldest first.
   */
  private function activitiesTo(string $inbox): array {
    $activities = [];
    foreach ($this->deliveriesTo($inbox) as $delivery) {
      array_push($activities, ...$this->activities($delivery));
    }
    return $activities;
  }

  /**
   * Tests that a move is one Move activity, from where it was to where it is.
   */
  public function testMove(): void {
    $this->assertSame(201, $this->subscribe(self::ALICE, ['root/'])->getStatusCode());
    $this->move('root/source/a.txt', 'root/destination/');

    $deliveries = $this->deliveriesTo();
    $this->assertCount(1, $deliveries);
    $notification = $this->verify($deliveries[0]);
    $this->assertSame(self::STORAGE, $notification->storage);
    $activities = $this->activities($deliveries[0]);
    $this->assertCount(1, $activities);
    $this->assertSame(['Move'], $activities[0]['type']);
    $this->assertSame(['id' => self::STORAGE . 'root/destination/a.txt', 'type' => ['DataResource']], $activities[0]['object']);
    $this->assertSame(self::STORAGE . 'root/source/', $activities[0]['origin']);
    $this->assertSame(self::STORAGE . 'root/destination/', $activities[0]['target']);
    $this->assertArrayNotHasKey('actor', $activities[0]);

    // A container moves with what it holds, in one Move.
    $this->deliveries = [];
    $this->move('root/destination/', 'root/source/');
    $activities = $this->activitiesTo(self::INBOX);
    $this->assertCount(1, $activities);
    $this->assertSame(['Move'], $activities[0]['type']);
    $this->assertSame(['id' => self::STORAGE . 'root/source/destination/', 'type' => ['Container']], $activities[0]['object']);
    $this->assertSame(self::STORAGE . 'root/', $activities[0]['origin']);
    $this->assertSame(self::STORAGE . 'root/source/', $activities[0]['target']);
  }

  /**
   * Tests what each subscriber hears, by what it may read.
   */
  public function testByReach(): void {
    $inboxes = [];
    foreach ([self::BOB => 'root/source/', self::CAROL => 'root/destination/', self::DAVE => 'root/elsewhere/'] as $agent => $path) {
      $inboxes[$agent] = 'https://inbox.example/hooks/' . md5($agent);
      $this->letRead($agent, [$path]);
      $response = $this->subscribe($agent, [$path], ['inbox' => $inboxes[$agent]]);
      $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    }
    // Alice hears of it at both ends, but once.
    $this->assertSame(201, $this->subscribe(self::ALICE, ['root/source/', 'root/destination/', 'root/source/a.txt'])->getStatusCode());
    $this->assertSame(201, $this->subscribe(self::ALICE, ['root/destination/'], ['inbox' => 'https://inbox.example/hooks/second'])->getStatusCode());

    $this->move('root/source/a.txt', 'root/destination/');
    $moved = ['id' => self::STORAGE . 'root/destination/a.txt', 'type' => ['DataResource']];
    $alice = $this->activitiesTo(self::INBOX);
    $this->assertCount(1, $alice);
    $this->assertSame(['Move'], $alice[0]['type']);
    $this->assertSame($moved, $alice[0]['object']);
    $this->assertSame(['Move'], $this->activitiesTo('https://inbox.example/hooks/second')[0]['type']);
    // Bob may read where it was, not where it is: it left Bob's view.
    $bob = $this->activitiesTo($inboxes[self::BOB]);
    $this->assertCount(1, $bob);
    $this->assertSame(['Delete'], $bob[0]['type']);
    $this->assertSame(['id' => self::STORAGE . 'root/source/a.txt', 'type' => ['DataResource']], $bob[0]['object']);
    $this->assertSame(self::STORAGE . 'root/source/', $bob[0]['origin']);
    $this->assertArrayNotHasKey('target', $bob[0]);
    $this->assertStringNotContainsString('destination', $this->deliveriesTo($inboxes[self::BOB])[0]['body']);
    // Carol may read where it is: it came into Carol's view, from source/.
    $carol = $this->activitiesTo($inboxes[self::CAROL]);
    $this->assertCount(1, $carol);
    $this->assertSame(['Move'], $carol[0]['type']);
    $this->assertSame($moved, $carol[0]['object']);
    $this->assertSame(self::STORAGE . 'root/source/', $carol[0]['origin']);
    $this->assertSame(self::STORAGE . 'root/destination/', $carol[0]['target']);
    // Dave's subscription covers neither end.
    $this->assertSame([], $this->deliveriesTo($inboxes[self::DAVE]));

    // Bob, given destination/ too, hears that it moved, though Bob's
    // subscription covers only where it was.
    $this->letRead(self::BOB, ['root/destination/']);
    $this->move('root/destination/a.txt', 'root/source/');
    $this->move('root/source/a.txt', 'root/elsewhere/');
    $bob = $this->activitiesTo($inboxes[self::BOB]);
    $this->assertCount(3, $bob);
    $this->assertSame(['Move'], $bob[1]['type']);
    $this->assertSame(self::STORAGE . 'root/source/', $bob[1]['target']);
    $this->assertSame(['Delete'], $bob[2]['type']);
    $this->assertSame(['Move'], $this->activitiesTo($inboxes[self::DAVE])[0]['type'] ?? NULL);
  }

}
