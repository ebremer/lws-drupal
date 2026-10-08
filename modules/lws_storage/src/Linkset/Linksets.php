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

/**
 * Linkset documents of resources (LWS Core §9.1, RFC 9264).
 *
 * A resource's linkset holds the server-managed relations, which the server
 * derives (up, and the LWS class among the types), and the relations its
 * clients manage. Clients set those with Link headers on create, with
 * "Prefer: set-linkset" on updates, or by changing the linkset itself; none
 * of these can change a server-managed relation.
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
        if (!str_starts_with($link->href, Vocabulary::LWS_NS)) {
          $types[] = $link->href;
        }
        continue;
      }
      if (in_array($link->rel, self::SERVER_MANAGED, TRUE)) {
        continue;
      }
      $target = self::target($link);
      if (!in_array($target, $targets[$link->rel] ?? [], TRUE)) {
        $targets[$link->rel][] = $target;
      }
    }
    return new UserMetadata(array_values(array_unique($types)), $targets);
  }

  /**
   * User metadata from a linkset document a client sent or a patch made.
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
   * @throws \Drupal\lws\Http\LwsHttpException
   *   422 when it is not a linkset of the resource; 409 when it would change
   *   a server-managed relation.
   */
  public function fromDocument(mixed $document, LwsStorageInterface $storage, LwsResourceInterface $resource, bool $complete): UserMetadata {
    $linkset = Json::members($document)['linkset'] ?? NULL;
    $context = Json::isList($linkset) && count($linkset) === 1 ? self::members($linkset[0]) : NULL;
    if ($context === NULL) {
      throw LwsHttpException::unprocessable('A linkset document has a "linkset" array with one link context object: the resource.');
    }
    $uri = $this->links->uri($storage, $resource);
    if (array_key_exists('anchor', $context) && $context['anchor'] !== $uri) {
      throw LwsHttpException::unprocessable('The linkset may only hold links whose anchor is the resource, ' . $uri . '.');
    }
    $parent = $resource->getParent();
    $up = $parent === NULL ? NULL : $this->links->uri($storage, $parent);
    $class = $this->links->type($resource);

    $types = [];
    $links = [];
    $sawClass = FALSE;
    $sawUp = FALSE;
    foreach ($context as $name => $value) {
      $rel = LinkHeader::normalizeRel((string) $name);
      if ($rel === 'anchor') {
        continue;
      }
      $targets = self::targets($value, $rel);
      $hrefs = array_column($targets, 'href');
      if ($rel === LinkRelation::TYPE) {
        foreach ($hrefs as $href) {
          if (!str_starts_with($href, Vocabulary::LWS_NS)) {
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
      elseif (in_array($rel, self::SERVER_MANAGED, TRUE)) {
        if ($rel !== LinkRelation::UP || $hrefs !== [$up]) {
          throw LwsHttpException::conflict(sprintf('The "%s" relation is server-managed and cannot be changed.', $rel));
        }
        $sawUp = TRUE;
      }
      elseif ($targets !== []) {
        $links[$rel] = $targets;
      }
    }
    if ($complete && (!$sawClass || ($up !== NULL && !$sawUp))) {
      throw LwsHttpException::conflict('The "up" relation and the LWS class among the types are server-managed and cannot be removed.');
    }
    return new UserMetadata(array_values(array_unique($types)), $links);
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
   * Whether a value is an absolute URI.
   */
  private static function isUri(string $value): bool {
    return preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:\S+$/', $value) === 1;
  }

}
