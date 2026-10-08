<?php

declare(strict_types=1);

namespace Drupal\lws\Routing;

use Drupal\Component\Utility\Unicode;

/**
 * Rules for the names of containers and data resources.
 *
 * A name is one decoded path segment. Containers are stored with a trailing
 * slash after the name; the rules apply to the name without it.
 */
final class ResourceName {

  /**
   * The longest name, in bytes.
   *
   * A stored name, with a container's trailing slash, fits a 255-character
   * column, and a data resource's name fits the file name of its content.
   */
  public const MAX_BYTES = 254;

  /**
   * Checks a decoded path segment of a request URL.
   *
   * @return string|null
   *   Why the segment cannot name a resource, or NULL if it can.
   */
  public static function syntaxError(string $name): ?string {
    return match (TRUE) {
      $name === '' => 'The path has an empty segment.',
      $name === '.', $name === '..' => 'The path has a dot segment.',
      str_contains($name, '/') => 'A resource name cannot contain an encoded slash.',
      !Unicode::validateUtf8($name) => 'A resource name must be UTF-8.',
      (bool) preg_match('/[\x00-\x1F\x7F]/', $name) => 'A resource name cannot contain control characters.',
      default => NULL,
    };
  }

  /**
   * Checks a name for a new resource.
   *
   * Stricter than syntaxError(): web servers commonly refuse path segments
   * that start with a dot (Drupal's .htaccess answers 403), so the server never
   * creates such a name.
   *
   * @return string|null
   *   Why the name cannot be given to a new resource, or NULL if it can.
   */
  public static function creationError(string $name): ?string {
    return self::syntaxError($name) ?? match (TRUE) {
      str_starts_with($name, '.') => 'A resource name cannot start with a dot.',
      strlen($name) > self::MAX_BYTES => 'A resource name is limited to ' . self::MAX_BYTES . ' bytes.',
      default => NULL,
    };
  }

}
