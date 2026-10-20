<?php

declare(strict_types=1);

namespace Drupal\lws\Routing;

use Drupal\Core\Config\ConfigFactoryInterface;
use Ebremer\Lws\Vocabulary;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds canonical, absolute URIs in the LWS URL space.
 *
 * LWS code builds protocol URIs here and never through Url::fromRoute():
 * the routes behind the URL space are internal (DESIGN.md §4.2).
 */
final class LwsUrlGenerator {

  /**
   * Characters that rawurlencode() escapes but RFC 3986 allows in a segment.
   */
  private const SEGMENT_SAFE = [
    '%21' => '!',
    '%24' => '$',
    '%26' => '&',
    '%27' => "'",
    '%28' => '(',
    '%29' => ')',
    '%2A' => '*',
    '%2B' => '+',
    '%2C' => ',',
    '%3A' => ':',
    '%3B' => ';',
    '%3D' => '=',
    '%40' => '@',
  ];

  public function __construct(
    private readonly LwsUrlParser $parser,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * The scheme, host and base path that LWS URIs start with.
   *
   * Comes from lws.settings:base_url. When that is empty it is derived from
   * the current request, which trusts the Host header: acceptable in
   * development, not in production (DESIGN.md §8.4).
   */
  public function baseUrl(): string {
    $configured = rtrim((string) $this->configFactory->get('lws.settings')->get('base_url'), '/');
    if ($configured !== '') {
      return $configured;
    }
    $request = $this->requestStack->getMainRequest();
    return $request === NULL ? '' : $request->getSchemeAndHttpHost() . $request->getBasePath();
  }

  /**
   * The URI of a storage: its id, and the audience of its tokens.
   */
  public function storageUri(string $storage): string {
    return $this->baseUrl() . $this->parser->prefix() . '/' . $storage . '/';
  }

  /**
   * The URL a client requested in the LWS URL space.
   *
   * The base URL, then the path as Drupal received it, still percent-encoded,
   * then the query as sent, if there was one: character for character the
   * URL the client asked for, which a client compares the "resource" of
   * protected resource metadata with (RFC 9728 §3.3).
   */
  public function requestUri(Request $request): string {
    return $this->baseUrl() . $request->getPathInfo() . self::rawQuery($request);
  }

  /**
   * The query of a request as sent, with its "?"; empty if it had none.
   *
   * Taken from the request URI: Request::getQueryString() sorts and
   * re-encodes the parameters.
   */
  public static function rawQuery(Request $request): string {
    $uri = $request->getRequestUri();
    $mark = strpos($uri, '?');
    return $mark === FALSE ? '' : substr($uri, $mark);
  }

  /**
   * The URL of the protected resource metadata of a resource (RFC 9728 §3.1).
   *
   * The well-known segment goes between the authority and the path, and the
   * query stays after the path. A path of "/" alone is dropped, as RFC 9728
   * drops a terminating slash after the host.
   *
   * @param string $resource
   *   The absolute URL of the resource.
   */
  public static function resourceMetadataUri(string $resource): string {
    if (preg_match('#^([A-Za-z][A-Za-z0-9+.-]*://[^/?\#]*)([^?\#]*)([^\#]*)#', $resource, $parts) !== 1) {
      return '';
    }
    return $parts[1] . Vocabulary::WELL_KNOWN_OAUTH_PROTECTED_RESOURCE . ($parts[2] === '/' ? '' : $parts[2]) . $parts[3];
  }

  /**
   * The URI of a container or data resource.
   *
   * @param string $storage
   *   The storage slug.
   * @param list<string> $segments
   *   The decoded names from the root container down, starting with 'root'.
   * @param bool $container
   *   Whether the resource is a container.
   */
  public function resourceUri(string $storage, array $segments, bool $container): string {
    $path = implode('/', array_map(self::encodeSegment(...), $segments));
    return $this->storageUri($storage) . $path . ($container ? '/' : '');
  }

  /**
   * The URI of an access service, or of one of its entries (LWS Core §11).
   *
   * @param string $storage
   *   The storage slug.
   * @param string $service
   *   The service: "requests" or "grants".
   * @param string|null $id
   *   The UUID of an entry; NULL for the service.
   */
  public function accessUri(string $storage, string $service, ?string $id = NULL): string {
    return $this->storageUri($storage) . 'access/' . $service . '/' . ($id ?? '');
  }

  /**
   * The URI of the notification service, or of one of its subscriptions.
   *
   * @param string $storage
   *   The storage slug.
   * @param string|null $id
   *   The UUID of a subscription; NULL for the service.
   */
  public function notificationsUri(string $storage, ?string $id = NULL): string {
    return $this->storageUri($storage) . 'notifications/' . ($id ?? '');
  }

  /**
   * The URI of the type index or type search service (lws10-index).
   *
   * @param string $storage
   *   The storage slug.
   * @param string $service
   *   The service: "index" or "search".
   */
  public function typesUri(string $storage, string $service): string {
    return $this->storageUri($storage) . 'types/' . $service;
  }

  /**
   * The URI of a resource's linkset.
   *
   * @param string $storage
   *   The storage slug.
   * @param string $uuid
   *   The UUID of the resource the linkset describes.
   */
  public function metaUri(string $storage, string $uuid): string {
    return $this->storageUri($storage) . 'meta/' . $uuid;
  }

  /**
   * The canonical URI of a target, if it has one.
   */
  public function targetUri(LwsTarget $target): ?string {
    if ($target->storage === NULL) {
      return NULL;
    }
    return match ($target->area) {
      LwsArea::Description => $this->storageUri($target->storage),
      LwsArea::Resource => $this->resourceUri($target->storage, $target->segments, $target->container),
      LwsArea::Meta => $this->metaUri($target->storage, (string) $target->metaId),
      LwsArea::Access => $this->accessUri($target->storage, (string) $target->service, $target->recordId),
      LwsArea::Notifications => $this->notificationsUri($target->storage, $target->recordId),
      LwsArea::Types => $this->typesUri($target->storage, (string) $target->service),
      LwsArea::Unknown, LwsArea::Malformed => NULL,
    };
  }

  /**
   * The URI of a resource's parent container, or NULL for the root.
   */
  public function parentUri(LwsTarget $target): ?string {
    if ($target->area !== LwsArea::Resource || $target->storage === NULL || count($target->segments) < 2) {
      return NULL;
    }
    return $this->resourceUri($target->storage, array_slice($target->segments, 0, -1), TRUE);
  }

  /**
   * Percent-encodes a resource name for use as one path segment.
   *
   * Encodes everything except RFC 3986 unreserved characters, sub-delimiters,
   * ":" and "@", so that names round-trip and percent-encoded unreserved
   * characters (such as "%7E") come back in their normal form ("~").
   */
  public static function encodeSegment(string $name): string {
    return strtr(rawurlencode($name), self::SEGMENT_SAFE);
  }

}
