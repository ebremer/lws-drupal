<?php

declare(strict_types=1);

namespace Drupal\lws\Routing;

use Ebremer\Lws\MediaType;

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
   * @param string|null $service
   *   For the access services, "requests" or "grants"; for the type
   *   services, "index" or "search".
   * @param string|null $recordId
   *   For an entry of an access service or a subscription of the
   *   notification service, its UUID; NULL for the service.
   */
  private function __construct(
    public readonly LwsArea $area,
    public readonly string $rawPath,
    public readonly ?string $storage = NULL,
    public readonly array $segments = [],
    public readonly bool $container = FALSE,
    public readonly ?string $metaId = NULL,
    public readonly ?string $error = NULL,
    public readonly ?string $service = NULL,
    public readonly ?string $recordId = NULL,
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
   * The access request or access grant service, or one of its entries.
   *
   * @param string $rawPath
   *   The request path.
   * @param string $storage
   *   The storage slug.
   * @param string $service
   *   The service: "requests" or "grants".
   * @param string|null $recordId
   *   The UUID of an entry; NULL for the service itself, a container.
   */
  public static function access(string $rawPath, string $storage, string $service, ?string $recordId): self {
    return new self(LwsArea::Access, $rawPath, $storage, container: $recordId === NULL, service: $service, recordId: $recordId);
  }

  /**
   * The notification service, or one of its subscriptions.
   *
   * @param string $rawPath
   *   The request path.
   * @param string $storage
   *   The storage slug.
   * @param string|null $subscriptionId
   *   The UUID of a subscription; NULL for the service itself, a container.
   */
  public static function notifications(string $rawPath, string $storage, ?string $subscriptionId): self {
    return new self(LwsArea::Notifications, $rawPath, $storage, container: $subscriptionId === NULL, recordId: $subscriptionId);
  }

  /**
   * The type index or type search service (lws10-index).
   *
   * @param string $rawPath
   *   The request path.
   * @param string $storage
   *   The storage slug.
   * @param string $service
   *   The service: "index" or "search".
   */
  public static function types(string $rawPath, string $storage, string $service): self {
    return new self(LwsArea::Types, $rawPath, $storage, service: $service);
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
      // The services are containers whose entries are created by POST, and
      // cancelled or revoked by DELETE (LWS Core §11.5).
      // So is the notification service: its entries are subscriptions, and
      // DELETE cancels one (lws10-notifications-webhook).
      LwsArea::Access, LwsArea::Notifications => $this->recordId === NULL
        ? ['GET', 'HEAD', 'POST', 'OPTIONS']
        : ['GET', 'HEAD', 'DELETE', 'OPTIONS'],
      LwsArea::Description => ['GET', 'HEAD', 'OPTIONS'],
      // A search is a QUERY (RFC 10008); the pages of its results, and of the
      // type index, are read with GET.
      LwsArea::Types => $this->service === 'search'
        ? ['GET', 'HEAD', 'QUERY', 'OPTIONS']
        : ['GET', 'HEAD', 'OPTIONS'],
      LwsArea::Unknown, LwsArea::Malformed => [],
    };
  }

  /**
   * The query formats a QUERY to the target may carry (RFC 10008 §3).
   *
   * Like the methods, they depend only on the shape of the URL.
   *
   * @return list<string>
   *   Media types; none where QUERY is not allowed.
   */
  public function queryFormats(): array {
    return in_array('QUERY', $this->allowedMethods(), TRUE) ? [MediaType::LWS_QUERY_JSON] : [];
  }

}
