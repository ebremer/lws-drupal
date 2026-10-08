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
      self::Unknown, self::Malformed => '/_lws/unknown',
    };
  }

}
