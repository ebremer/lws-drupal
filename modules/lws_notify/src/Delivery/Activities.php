<?php

declare(strict_types=1);

namespace Drupal\lws_notify\Delivery;

use Drupal\lws_storage\LwsResourceEvent;
use Ebremer\Lws\ActivityType;
use Ebremer\Lws\ResourceType;

/**
 * Makes Activity Streams 2.0 activities about changes (LWS Core §10.2.2).
 *
 * Each has an "id", a "type" array, an "object" with the resource's "id" and
 * "type" array, and a "published" time; a Create names the container the
 * resource was added to as its "target", and a Delete the one it was removed
 * from as its "origin" (§10.2.3).
 */
final class Activities {

  /**
   * The activity type of each resource event.
   */
  public const TYPES = [
    LwsResourceEvent::CREATED => ActivityType::CREATE,
    LwsResourceEvent::UPDATED => ActivityType::UPDATE,
    LwsResourceEvent::METADATA_UPDATED => ActivityType::UPDATE,
    LwsResourceEvent::DELETED => ActivityType::DELETE,
  ];

  /**
   * The activity about a change to a resource.
   *
   * @param string $name
   *   The event name: an LwsResourceEvent constant.
   * @param \Drupal\lws_storage\LwsResourceEvent $event
   *   The event.
   * @param string $id
   *   A UUID for the activity.
   * @param string|null $actor
   *   The agent who made the change, if it is to be named.
   *
   * @return array<string, mixed>
   *   The activity.
   */
  public static function forResource(string $name, LwsResourceEvent $event, string $id, ?string $actor = NULL): array {
    $resource = $event->resource;
    // The LWS class as the term the context defines, then the types clients
    // declared, which are absolute URIs.
    $class = $resource->container ? 'Container' : 'DataResource';
    $types = [$class];
    foreach ($resource->types as $type) {
      if ($type !== ResourceType::CONTAINER && $type !== ResourceType::DATA_RESOURCE && !in_array($type, $types, TRUE)) {
        $types[] = $type;
      }
    }
    $type = self::TYPES[$name] ?? ActivityType::UPDATE;
    $activity = [
      'id' => 'urn:uuid:' . $id,
      'type' => [$type],
      'object' => ['id' => $resource->uri, 'type' => $types],
      'published' => self::time($event->time),
    ];
    $parent = $event->parentUri();
    if ($parent !== NULL && $type === ActivityType::CREATE) {
      $activity['target'] = $parent;
    }
    if ($parent !== NULL && $type === ActivityType::DELETE) {
      $activity['origin'] = $parent;
    }
    if ($actor !== NULL) {
      $activity['actor'] = $actor;
    }
    return $activity;
  }

  /**
   * The activity about an access request or grant (LWS Core §11.6).
   *
   * @param string $type
   *   The activity type: Create or Delete.
   * @param string $uri
   *   The request's or grant's URI.
   * @param string $kind
   *   Its type: AccessRequest or AccessGrant.
   * @param string $service
   *   The URI of the service it is in.
   * @param string $id
   *   A UUID for the activity.
   * @param float $time
   *   When it happened, in seconds since the epoch.
   * @param string|null $actor
   *   The agent who did it, if it is to be named.
   *
   * @return array<string, mixed>
   *   The activity.
   */
  public static function forAccessRecord(string $type, string $uri, string $kind, string $service, string $id, float $time, ?string $actor = NULL): array {
    $activity = [
      'id' => 'urn:uuid:' . $id,
      'type' => [$type],
      'object' => ['id' => $uri, 'type' => ['DataResource', $kind]],
      'published' => self::time($time),
    ];
    $activity[$type === ActivityType::DELETE ? 'origin' : 'target'] = $service;
    if ($actor !== NULL) {
      $activity['actor'] = $actor;
    }
    return $activity;
  }

  /**
   * An RFC 3339 time in UTC, to the millisecond.
   */
  public static function time(float $time): string {
    $seconds = (int) floor($time);
    return gmdate('Y-m-d\TH:i:s', $seconds) . sprintf('.%03dZ', min(999, (int) floor(($time - $seconds) * 1000)));
  }

}
