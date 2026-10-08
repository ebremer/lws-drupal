<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_notify\Unit;

use Drupal\lws\Access\ResourceContext;
use Drupal\lws\Storage\StorageRef;
use Drupal\lws_notify\Delivery\Activities;
use Drupal\lws_notify\Delivery\Delivery;
use Drupal\lws_notify\Delivery\DeliveryOutcome;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests deliveries, their outcomes and their activities' times.
 */
#[Group('lws')]
final class DeliveryTest extends UnitTestCase {

  /**
   * Tests how inbox answers are classified.
   */
  public function testOutcomes(): void {
    $outcomes = [];
    foreach ([200, 202, 204, 0, 429, 500, 503, 301, 400, 401, 404, 410, 413] as $status) {
      $outcomes[$status] = DeliveryOutcome::forStatus($status)->name;
    }
    $this->assertSame([
      200 => 'Delivered',
      202 => 'Delivered',
      204 => 'Delivered',
      0 => 'Retry',
      429 => 'Retry',
      500 => 'Retry',
      503 => 'Retry',
      301 => 'Failed',
      400 => 'Failed',
      401 => 'Failed',
      404 => 'Failed',
      410 => 'Gone',
      413 => 'Failed',
    ], $outcomes);
  }

  /**
   * Tests the RFC 3339 times of activities.
   */
  public function testTime(): void {
    $this->assertSame('2026-10-08T12:00:00.000Z', Activities::time(1791460800.0));
    $this->assertSame('2026-10-08T12:00:00.999Z', Activities::time(1791460800.9999));
    $this->assertSame('2026-10-08T12:00:01.250Z', Activities::time(1791460801.25));
  }

  /**
   * Tests a notification's body, and a delivery's way through the queue.
   */
  public function testBodyAndQueue(): void {
    $context = new ResourceContext(new StorageRef(1, 'alice', 'https://s.example/lws/alice/', []), 'https://s.example/lws/alice/root/a', ['https://s.example/lws/alice/root/'], FALSE);
    $delivery = new Delivery('https://inbox.example/', 'https://s.example/lws/alice/', 7);
    $delivery->add(['id' => 'urn:uuid:1', 'type' => ['Update']], $context);
    $body = json_decode($delivery->body(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame(['id' => 'urn:uuid:1', 'type' => ['Update']], $body['activity']);
    $delivery->add(['id' => 'urn:uuid:2', 'type' => ['Delete']]);
    $body = json_decode($delivery->body(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame(['urn:uuid:1', 'urn:uuid:2'], array_column($body['activity'], 'id'));
    $this->assertSame('Notification', $body['type']);
    $this->assertSame('https://s.example/lws/alice/', $body['storage']);

    $item = unserialize(serialize($delivery->toItem(1234)), [
      'allowed_classes' => [ResourceContext::class, StorageRef::class],
    ]);
    $this->assertSame(1234, $item['not_before']);
    $copy = Delivery::fromItem($item);
    $this->assertNotNull($copy);
    $this->assertSame([7, 1, $delivery->body()], [$copy->subscription, $copy->attempt, $copy->body()]);
    $kept = [];
    $copy->filter(function (array $activity, ?ResourceContext $context) use (&$kept): bool {
      $kept[] = $context?->uri;
      return $context !== NULL;
    });
    $this->assertSame(['https://s.example/lws/alice/root/a', NULL], $kept);
    $this->assertSame('urn:uuid:1', json_decode($copy->body(), TRUE, 512, JSON_THROW_ON_ERROR)['activity']['id']);

    $this->assertNull(Delivery::fromItem('not an item'));
    $this->assertNull(Delivery::fromItem(['inbox' => 'https://inbox.example/']));
    for ($i = 1; $i < Delivery::MAX_ACTIVITIES; $i++) {
      $copy->add(['id' => 'urn:uuid:x' . $i]);
    }
    $this->assertTrue($copy->isFull());
  }

}
