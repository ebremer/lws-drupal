<?php

declare(strict_types=1);

namespace Drupal\lws\Routing;

/**
 * The parts of the LWS URL space a request can address (DESIGN.md §4.1).
 */
enum LwsArea: string {

  // The storage URI itself, which serves the storage description.
  case Description = 'description';

  // A container or data resource under the storage root.
  case Resource = 'resource';

  // The linkset resource of a container or data resource.
  case Meta = 'meta';
  // The access request or access grant service, or one of its entries.
  case Access = 'access';

  // The notification service, or one of its subscriptions.
  case Notifications = 'notifications';

  // The type index or type search service.
  case Types = 'types';

  // Well-formed, but nothing can exist there.
  case Unknown = 'unknown';

  // Not a valid LWS URL, for example one with a dot segment.
  case Malformed = 'malformed';

  /**
   * The internal path that LwsPathProcessor routes this area to.
   */
  public function internalPath(): string {
    return match ($this) {
      self::Description => '/_lws/description',
      self::Resource => '/_lws/resource',
      self::Meta => '/_lws/meta',
      self::Access => '/_lws/access',
      self::Notifications => '/_lws/notifications',
      self::Types => '/_lws/types',
      self::Unknown, self::Malformed => '/_lws/unknown',
    };
  }

}
