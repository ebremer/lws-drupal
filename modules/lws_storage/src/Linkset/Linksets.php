<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Linkset;

use Drupal\lws\Http\LwsHttpException;
use Drupal\lws\Http\LwsResponse;
use Drupal\lws_storage\Entity\LwsResourceInterface;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\ResourceLinks;
use Ebremer\Lws\Http\Link;
use Ebremer\Lws\Http\LinkHeader;
use Ebremer\Lws\Json\Json;
use Ebremer\Lws\LinkRelation;
use Ebremer\Lws\Vocabulary;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

/**
 * Linkset documents of resources (LWS Core §9.1, RFC 9264).
 *
 * A resource's linkset holds the server-managed relations, which the server
 * derives (up, and the LWS class among the types), and the relations its
 * clients manage. Clients set those with Link headers on create, with
 * "Prefer: set-linkset" on updates, or by changing the linkset itself; none
 * of these can change a server-managed relation, but for "up" in the
 * linkset itself: a client moves a resource to another container by changing
 * it (LWS Core, Moving Resources), which StorageManager::changeLinkset()
 * decides on.
 */
final class Linksets {

  /**
   * Relations only the server sets.
   */
  public const SERVER_MANAGED = [
    LinkRelation::UP,
    LinkRelation::LINKSET,
    LinkRelation::STORAGE,
    LinkRelation::FIRST,
    LinkRelation::NEXT,
    LinkRelation::PREV,
    LinkRelation::LAST,
  ];

  /**
   * Target attributes whose values are single strings (RFC 9264 §4.2.4.1).
   */
  private const STRING_ATTRIBUTES = ['media', 'title', 'type'];

  /**
   * The most types a client may give a resource.
   *
   * They are sent in a Link header with every response about it, which
   * proxies limit.
   */
  public const MAX_TYPES = 32;

  /**
   * The longest link target, in bytes.
   */
  public const MAX_HREF_BYTES = 2048;

  /**
   * An absolute URI (RFC 3986 §4.3).
   *
   * It has nothing a Link header would need to escape, such as ">", quotes,
   * spaces or control characters.
   */
  private const ABSOLUTE_URI = '/^[A-Za-z][A-Za-z0-9+.-]*:[A-Za-z0-9\-._~:\/?#\[\]@!$&\'()*+,;=%]+$/';

  public function __construct(
    private readonly ResourceLinks $links,
  ) {}

  /**
   * The linkset document of a resource, as a GET returns it.
   *
   * @return array{linkset: list<array<string, mixed>>}
   *   The document.
   */
  public function document(LwsStorageInterface $storage, LwsResourceInterface $resource): array {
    $context = ['anchor' => $this->links->uri($storage, $resource)];
    $parent = $resource->getParent();
    if ($parent !== NULL) {
      $context[LinkRelation::UP] = [['href' => $this->links->uri($storage, $parent)]];
    }
    $metadata = $resource->getUserMetadata();
    $context[LinkRelation::TYPE] = [['href' => $this->links->type($resource)]];
    foreach ($metadata->types as $type) {
      $context[LinkRelation::TYPE][] = ['href' => $type];
    }
    foreach ($metadata->links as $rel => $targets) {
      $context[$rel] = $targets;
    }
    return ['linkset' => [$context]];
  }

  /**
   * The entity tag of a linkset document.
   *
   * It depends on the document only, so the same tag serves every media type
   * the linkset is offered in, and conditional writes work whichever one a
   * client read.
   *
   * @param array<string, mixed> $document
   *   The document.
   */
  public static function etag(array $document): string {
    return LwsResponse::etag(Json::encode($document));
  }

  /**
   * User metadata from the Link headers of a write (§9.2).
   *
   * Server-managed relations, LWS classes and links about another context
   * (with an anchor) are ignored, as clients may not set them.
   *
   * @param list<\Ebremer\Lws\Http\Link> $links
   *   The parsed Link headers.
   */
  public function fromLinkHeaders(array $links): UserMetadata {
    $types = [];
    $targets = [];
    foreach ($links as $link) {
      if (isset($link->params['anchor']) || !self::isUri($link->href)) {
        continue;
      }
      if ($link->rel === LinkRelation::TYPE) {
        if (!self::isLws($link->href)) {
          $types[] = $link->href;
        }
        continue;
      }
      if (self::isServerManaged($link->rel)) {
        continue;
      }
      $target = self::target($link);
      if (!in_array($target, $targets[$link->rel] ?? [], TRUE)) {
        $targets[$link->rel][] = $target;
      }
    }
    return new UserMetadata(self::limitTypes($types), $targets);
  }

  /**
   * What a linkset document a client sent, or a patch made, asks for.
   *
   * @param mixed $document
   *   The document, as Json::decode() returns it.
   * @param \Drupal\lws_storage\Entity\LwsStorageInterface $storage
   *   The storage.
   * @param \Drupal\lws_storage\Entity\LwsResourceInterface $resource
   *   The resource the linkset describes.
   * @param bool $complete
   *   Whether the server-managed relations must still be there, as in the
   *   result of patching the document a GET returns; a document a client
   *   PUTs may leave them out.
   *
   * @return \Drupal\lws_storage\Linkset\LinksetUpdate
   *   The links clients manage, and the container the "up" link names.
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   422 when it is not a linkset of the resource; 409 when it would change
   *   a server-managed relation other than "up", or leave the resource in
   *   no container or in more than one.
   */
  public function fromDocument(mixed $document, LwsStorageInterface $storage, LwsResourceInterface $resource, bool $complete): LinksetUpdate {
    $linkset = Json::members($document)['linkset'] ?? NULL;
    $context = Json::isList($linkset) && count($linkset) === 1 ? self::members($linkset[0]) : NULL;
    if ($context === NULL) {
      throw LwsHttpException::unprocessable('A linkset document has a "linkset" array with one link context object: the resource.');
    }
    $uri = $this->links->uri($storage, $resource);
    if (array_key_exists('anchor', $context) && $context['anchor'] !== $uri) {
      throw LwsHttpException::unprocessable('The linkset may only hold links whose anchor is the resource, ' . $uri . '.');
    }
    $class = $this->links->type($resource);

    $types = [];
    $links = [];
    $sawClass = FALSE;
    $ups = NULL;
    foreach ($context as $name => $value) {
      $rel = LinkHeader::normalizeRel((string) $name);
      if ($rel === 'anchor') {
        continue;
      }
      if ($rel === LinkRelation::UP) {
        $ups = [...($ups ?? []), ...self::upTargets($value, $this->links->linksetUri($storage, $resource))];
        continue;
      }
      $targets = self::targets($value, $rel);
      $hrefs = array_column($targets, 'href');
      if ($rel === LinkRelation::TYPE) {
        foreach ($hrefs as $href) {
          if (!self::isLws($href)) {
            $types[] = $href;
          }
          elseif ($href === $class) {
            $sawClass = TRUE;
          }
          else {
            throw LwsHttpException::conflict(sprintf('The resource is a %s; the linkset cannot make it a %s.', $class, $href));
          }
        }
      }
      elseif (self::isServerManaged($rel)) {
        throw LwsHttpException::conflict(sprintf('The "%s" relation is server-managed and cannot be changed.', $rel));
      }
      elseif ($targets !== []) {
        $links[$rel] = $targets;
      }
    }
    if ($complete && !$sawClass) {
      throw LwsHttpException::conflict('The LWS class among the types is server-managed and cannot be removed.');
    }
    return new LinksetUpdate(new UserMetadata(self::limitTypes($types), $links), self::up($resource, $ups, $complete));
  }

  /**
   * The container a linkset document asks its resource to be in.
   *
   * Every resource but the storage root is in one container (LWS Core §7.3),
   * so the "up" link of any other has one target, and the root's none.
   *
   * @param \Drupal\lws_storage\Entity\LwsResourceInterface $resource
   *   The resource.
   * @param list<string>|null $ups
   *   The targets of the "up" link, as absolute URIs; NULL when the document
   *   has none.
   * @param bool $complete
   *   Whether the document must still have the link.
   *
   * @return string|null
   *   The container's URI; NULL when the document leaves the resource where
   *   it is without naming it, or it is the root.
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   409 for any other number of targets.
   */
  private static function up(LwsResourceInterface $resource, ?array $ups, bool $complete): ?string {
    if ($resource->isRoot()) {
      if ($ups !== NULL && $ups !== []) {
        throw LwsHttpException::conflict('The storage root is in no container, so it has no "up" link.');
      }
      return NULL;
    }
    if ($ups === NULL && !$complete) {
      return NULL;
    }
    if ($ups === NULL || $ups === []) {
      throw LwsHttpException::conflict('Every resource but the storage root is in a container, so its "up" link cannot be removed.');
    }
    if (count($ups) > 1) {
      throw LwsHttpException::conflict('A resource is in one container, so its "up" link has one target.');
    }
    return $ups[0];
  }

  /**
   * The targets of the "up" relation in a linkset document, as absolute URIs.
   *
   * A target may be a relative reference, which is resolved against the URL
   * of the linkset (RFC 9264 §4.2.2, RFC 3986 §5), as in LWS Core's own
   * example of a move. The links of other relations must be absolute.
   *
   * @return list<string>
   *   The URIs.
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   422 when they are not an array of target objects whose "href" is a URI
   *   reference.
   */
  private static function upTargets(mixed $value, string $base): array {
    if (!Json::isList($value)) {
      throw LwsHttpException::unprocessable('The "up" relation must be an array of target objects.');
    }
    $hrefs = [];
    foreach ($value as $target) {
      $href = (self::members($target) ?? [])['href'] ?? NULL;
      $resolved = is_string($href) ? self::resolve($base, $href) : NULL;
      if ($resolved === NULL) {
        throw LwsHttpException::unprocessable('Every target of the "up" relation needs an "href" that is a URI reference.');
      }
      $hrefs[] = $resolved;
    }
    return $hrefs;
  }

  /**
   * A URI reference resolved against a base URI (RFC 3986 §5).
   *
   * Unlike the targets clients set, it is never stored, so it may be longer
   * than MAX_HREF_BYTES, as the URI of a deeply nested container is.
   *
   * @return string|null
   *   The absolute URI; NULL when the reference is not one, or resolves to
   *   one that cannot be sent as it is.
   */
  private static function resolve(string $base, string $reference): ?string {
    try {
      $uri = (string) UriResolver::resolve(new Uri($base), new Uri($reference));
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
    return preg_match(self::ABSOLUTE_URI, $uri) === 1 ? $uri : NULL;
  }

  /**
   * The target objects of one relation in a linkset document.
   *
   * @return list<array<string, mixed>>
   *   Targets, each with an absolute "href".
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   422 when they are not an array of target objects.
   */
  private static function targets(mixed $value, string $rel): array {
    if (!Json::isList($value)) {
      throw LwsHttpException::unprocessable(sprintf('The "%s" relation must be an array of target objects.', $rel));
    }
    $targets = [];
    foreach ($value as $target) {
      $members = self::members($target);
      if ($members === NULL || !is_string($members['href'] ?? NULL) || !self::isUri($members['href'])) {
        throw LwsHttpException::unprocessable(sprintf('Every target of the "%s" relation needs an absolute "href".', $rel));
      }
      $targets[] = ['href' => $members['href']] + $members;
    }
    return $targets;
  }

  /**
   * The target object of a Link header (RFC 9264 §4.2.4).
   *
   * @return array<string, mixed>
   *   The target, with its attributes.
   */
  private static function target(Link $link): array {
    $target = ['href' => $link->href];
    foreach ($link->params as $name => $value) {
      if (in_array($name, ['rel', 'rev', 'anchor'], TRUE)) {
        continue;
      }
      if (str_ends_with($name, '*')) {
        $target[$name] = [['value' => LinkHeader::decodeExtValue($value) ?? $value]];
      }
      else {
        $target[$name] = in_array($name, self::STRING_ATTRIBUTES, TRUE) ? $value : [$value];
      }
    }
    return $target;
  }

  /**
   * The members of a JSON object, including an empty one; NULL otherwise.
   *
   * @return array<array-key, mixed>|null
   *   The members.
   */
  private static function members(mixed $value): ?array {
    return $value instanceof \stdClass ? get_object_vars($value) : Json::members($value);
  }

  /**
   * Whether a value is an absolute URI that can be sent as it is.
   */
  public static function isUri(string $value): bool {
    return strlen($value) <= self::MAX_HREF_BYTES && preg_match(self::ABSOLUTE_URI, $value) === 1;
  }

  /**
   * Whether a URI is in the LWS namespace, in any case.
   *
   * Its scheme and host are case-insensitive, so a type that differs only in
   * case may be taken for an LWS class.
   */
  private static function isLws(string $uri): bool {
    return stripos($uri, Vocabulary::LWS_NS) === 0;
  }

  /**
   * Whether a relation is one only the server sets.
   *
   * Relation types compare case-insensitively (RFC 8288 §2.1), extension
   * relations too.
   */
  private static function isServerManaged(string $rel): bool {
    return in_array(strtolower($rel), array_map('strtolower', self::SERVER_MANAGED), TRUE);
  }

  /**
   * The distinct types, if there are not too many.
   *
   * @param list<string> $types
   *   The types.
   *
   * @return list<string>
   *   The distinct types.
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   422 for more than MAX_TYPES.
   */
  private static function limitTypes(array $types): array {
    $types = array_values(array_unique($types));
    if (count($types) > self::MAX_TYPES) {
      throw LwsHttpException::unprocessable(sprintf('A resource may have at most %d types.', self::MAX_TYPES));
    }
    return $types;
  }

}
