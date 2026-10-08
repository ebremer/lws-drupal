<?php

declare(strict_types=1);

namespace Drupal\lws\Routing;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Parses request paths into targets in the LWS URL space.
 *
 * The parser works on the raw request path: percent-encoded, case preserved,
 * and with its trailing slash, which is what tells a container ("…/notes/")
 * from a data resource ("…/notes"). Drupal's router sees none of that: it
 * right-trims slashes, matches lower-cased outlines, and cannot match a
 * parameter that spans "/" (DESIGN.md §4.2).
 *
 * Layout under the prefix (DESIGN.md §4.1):
 * - {storage}/            the storage description;
 * - {storage}/root/…      containers ("…/") and data resources;
 * - {storage}/meta/{uuid} linkset resources;
 * - {storage}/access/{requests|grants}/[{uuid}] the access services;
 * - {storage}/notifications/[{uuid}] the notification service;
 * - {storage}/types/{index|search} the type index and type search services.
 */
final class LwsUrlParser {

  /**
   * First segments under the prefix that are not storages.
   *
   * The authorization server and agent documents are ordinary Drupal routes
   * under the same prefix.
   */
  public const RESERVED = ['agents', 'groups', 'oauth', 'roles'];

  /**
   * A storage slug.
   */
  public const SLUG = '/^[a-z0-9][a-z0-9-]{0,62}$/';

  /**
   * A lower-case UUID, which names a linkset resource.
   */
  private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

  /**
   * One path segment of RFC 3986 "pchar" characters and percent-encodings.
   */
  private const SEGMENT = '/^(?:[A-Za-z0-9\-._~!$&\'()*+,;=:@]|%[0-9A-Fa-f]{2})+$/';

  /**
   * The last path parsed, keyed with the prefix it was parsed under.
   */
  private ?string $lastKey = NULL;

  /**
   * The result of parsing $lastKey.
   */
  private ?LwsTarget $lastTarget = NULL;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * The path prefix of the LWS URL space, such as "/lws".
   */
  public function prefix(): string {
    return (string) $this->configFactory->get('lws.settings')->get('prefix');
  }

  /**
   * Parses a raw request path.
   *
   * @param string $rawPath
   *   The path as Request::getPathInfo() returns it: not percent-decoded,
   *   without the base path or query string.
   *
   * @return \Drupal\lws\Routing\LwsTarget|null
   *   The target, or NULL when the path is outside the LWS URL space or under
   *   a reserved segment.
   */
  public function parse(string $rawPath): ?LwsTarget {
    // Several services parse the same request path; it is a pure function of
    // the path and the prefix.
    $prefix = $this->prefix();
    $key = $prefix . "\0" . $rawPath;
    if ($key !== $this->lastKey) {
      $this->lastTarget = $this->doParse($rawPath, $prefix);
      $this->lastKey = $key;
    }
    return $this->lastTarget;
  }

  /**
   * Parses a raw request path under the given prefix.
   */
  private function doParse(string $rawPath, string $prefix): ?LwsTarget {
    if ($prefix === '' || !str_starts_with($rawPath, $prefix . '/')) {
      return NULL;
    }
    $rest = substr($rawPath, strlen($prefix) + 1);
    $slash = strpos($rest, '/');
    $storage = $slash === FALSE ? $rest : substr($rest, 0, $slash);
    if (in_array($storage, self::RESERVED, TRUE)) {
      return NULL;
    }
    // A storage URI always ends in a slash; "/lws/alice" is not one.
    if ($slash === FALSE || !preg_match(self::SLUG, $storage)) {
      return LwsTarget::unknown($rawPath);
    }

    $path = substr($rest, $slash + 1);
    if ($path === '') {
      return LwsTarget::description($rawPath, $storage);
    }
    if (str_starts_with($path, 'root/')) {
      return $this->parseResource($rawPath, $storage, $path);
    }
    if (str_starts_with($path, 'meta/') && preg_match(self::UUID, substr($path, 5))) {
      return LwsTarget::meta($rawPath, $storage, substr($path, 5));
    }
    if (preg_match('#^access/(requests|grants)/(' . substr(self::UUID, 2, -2) . ')?$#', $path, $matches) === 1) {
      return LwsTarget::access($rawPath, $storage, $matches[1], ($matches[2] ?? '') === '' ? NULL : $matches[2]);
    }
    if (preg_match('#^notifications/(' . substr(self::UUID, 2, -2) . ')?$#', $path, $matches) === 1) {
      return LwsTarget::notifications($rawPath, $storage, ($matches[1] ?? '') === '' ? NULL : $matches[1]);
    }
    if ($path === 'types/index' || $path === 'types/search') {
      return LwsTarget::types($rawPath, $storage, substr($path, 6));
    }
    return LwsTarget::unknown($rawPath, $storage);
  }

  /**
   * Parses the path of a container or data resource.
   *
   * @param string $rawPath
   *   The request path.
   * @param string $storage
   *   The storage slug.
   * @param string $path
   *   The part of the path after the storage URI, starting with "root/".
   */
  private function parseResource(string $rawPath, string $storage, string $path): LwsTarget {
    $parts = explode('/', $path);
    $container = end($parts) === '';
    if ($container) {
      array_pop($parts);
    }
    $segments = [];
    foreach ($parts as $part) {
      $error = $this->segmentError($part);
      if ($error !== NULL) {
        return LwsTarget::malformed($rawPath, $storage, $error);
      }
      $segments[] = rawurldecode($part);
    }
    return LwsTarget::resource($rawPath, $storage, $segments, $container);
  }

  /**
   * Checks one raw path segment of a resource path.
   *
   * @return string|null
   *   Why the segment cannot name a resource, or NULL if it can.
   */
  private function segmentError(string $segment): ?string {
    if ($segment !== '' && !preg_match(self::SEGMENT, $segment)) {
      return 'The path has a character that must be percent-encoded, or a malformed percent-encoding.';
    }
    return ResourceName::syntaxError(rawurldecode($segment));
  }

}
