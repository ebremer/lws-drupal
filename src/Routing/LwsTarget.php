<?php

declare(strict_types=1);

namespace Drupal\lws\Routing;

/**
 * What a request URL addresses in the LWS URL space.
 *
 * Built by LwsUrlParser from the raw request path. Resource names are held
 * percent-decoded; LwsUrlGenerator encodes them again for canonical URIs.
 */
final class LwsTarget {

  /**
   * Constructs a target; use the named constructors.
   *
   * @param \Drupal\lws\Routing\LwsArea $area
   *   The part of the URL space addressed.
   * @param string $rawPath
   *   The request path as received: percent-encoded, with its trailing slash.
   * @param string|null $storage
   *   The storage slug, when the path names a well-formed one.
   * @param list<string> $segments
   *   For resources, the decoded names from the root container down,
   *   starting with 'root'.
   * @param bool $container
   *   For resources, whether the path ends in a slash.
   * @param string|null $metaId
   *   For linkset resources, the UUID of the described resource.
   * @param string|null $error
   *   For malformed paths, why the path was rejected.
   */
  private function __construct(
    public readonly LwsArea $area,
    public readonly string $rawPath,
    public readonly ?string $storage = NULL,
    public readonly array $segments = [],
    public readonly bool $container = FALSE,
    public readonly ?string $metaId = NULL,
    public readonly ?string $error = NULL,
  ) {}

  /**
   * The storage URI, which serves the storage description.
   */
  public static function description(string $rawPath, string $storage): self {
    return new self(LwsArea::Description, $rawPath, $storage);
  }

  /**
   * A container or data resource.
   *
   * @param string $rawPath
   *   The request path.
   * @param string $storage
   *   The storage slug.
   * @param list<string> $segments
   *   The decoded names, starting with 'root'.
   * @param bool $container
   *   Whether the path ends in a slash.
   */
  public static function resource(string $rawPath, string $storage, array $segments, bool $container): self {
    return new self(LwsArea::Resource, $rawPath, $storage, $segments, $container);
  }

  /**
   * The linkset resource of the resource with the given UUID.
   */
  public static function meta(string $rawPath, string $storage, string $metaId): self {
    return new self(LwsArea::Meta, $rawPath, $storage, metaId: $metaId);
  }

  /**
   * A well-formed path at which nothing can exist.
   */
  public static function unknown(string $rawPath, ?string $storage = NULL): self {
    return new self(LwsArea::Unknown, $rawPath, $storage);
  }

  /**
   * A path that is not a valid LWS URL.
   */
  public static function malformed(string $rawPath, string $storage, string $error): self {
    return new self(LwsArea::Malformed, $rawPath, $storage, error: $error);
  }

  /**
   * Whether this is the storage root container.
   */
  public function isRoot(): bool {
    return $this->area === LwsArea::Resource && $this->segments === ['root'];
  }

  /**
   * The methods for the kind of resource the target addresses.
   *
   * Depends only on the shape of the URL, never on whether the resource
   * exists, so that OPTIONS cannot reveal existence. Containers take POST,
   * data resources PUT and PATCH, linksets PUT and PATCH; the root container
   * cannot be deleted.
   *
   * @return list<string>
   *   HTTP method names.
   */
  public function allowedMethods(): array {
    return match ($this->area) {
      LwsArea::Resource => match (TRUE) {
        $this->isRoot() => ['GET', 'HEAD', 'POST', 'OPTIONS'],
        $this->container => ['GET', 'HEAD', 'POST', 'DELETE', 'OPTIONS'],
        default => ['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
      },
      LwsArea::Meta => ['GET', 'HEAD', 'PUT', 'PATCH', 'OPTIONS'],
      LwsArea::Description => ['GET', 'HEAD', 'OPTIONS'],
      LwsArea::Unknown, LwsArea::Malformed => [],
    };
  }

}
