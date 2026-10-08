<?php

declare(strict_types=1);

namespace Drupal\lws_storage;

/**
 * Turns identity hints into names for new resources.
 *
 * The hint is the Slug header (RFC 5023 §9.7), percent-decoded and reduced to
 * unreserved URI characters, so that names never need encoding. A name never
 * starts with a dot: Drupal's .htaccess refuses such path segments.
 */
final class ResourceNames {

  /**
   * The longest name made from a hint, in bytes, leaving room for a suffix.
   */
  public const MAX_HINT_BYTES = 200;

  /**
   * The suffixed names tried before falling back to a generated one.
   */
  public const MAX_SUFFIX = 100;

  /**
   * The name a hint suggests; NULL if nothing usable is left of it.
   */
  public static function fromHint(?string $hint): ?string {
    if ($hint === NULL) {
      return NULL;
    }
    $name = preg_replace('/[^A-Za-z0-9._~-]+/', '-', rawurldecode($hint)) ?? '';
    $name = preg_replace('/-{2,}/', '-', $name) ?? '';
    $name = rtrim(ltrim(substr($name, 0, self::MAX_HINT_BYTES), '.-'), '-');
    return $name === '' ? NULL : $name;
  }

  /**
   * The names to try for a new resource, in order.
   *
   * The hint's name, then the same with a number before any extension
   * ("a.txt", "a-1.txt", "a-2.txt"), then a generated name.
   *
   * @param string|null $hint
   *   The identity hint.
   * @param bool $container
   *   Whether the resource is a container, whose names end in a slash.
   * @param callable(): string $generate
   *   Generates a name, such as a UUID.
   *
   * @return \Generator<int, string>
   *   Stored names: decoded, with a slash for containers.
   */
  public static function candidates(?string $hint, bool $container, callable $generate): \Generator {
    $slash = $container ? '/' : '';
    $name = self::fromHint($hint);
    if ($name !== NULL) {
      yield $name . $slash;
      $dot = $container ? FALSE : strrpos($name, '.');
      [$base, $extension] = $dot === FALSE || $dot === 0 ? [$name, ''] : [substr($name, 0, $dot), substr($name, $dot)];
      for ($i = 1; $i <= self::MAX_SUFFIX; $i++) {
        yield $base . '-' . $i . $extension . $slash;
      }
    }
    yield $generate() . $slash;
  }

}
