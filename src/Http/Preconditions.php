<?php

declare(strict_types=1);

namespace Drupal\lws\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Evaluates conditional requests (RFC 9110 §13.2.2).
 *
 * Entity tags are strong and passed unquoted. Range and If-Range are left to
 * the response that serves the range.
 */
final class Preconditions {

  /**
   * Evaluates the preconditions of a request against a resource's state.
   *
   * Call it for writes inside the transaction, against a locked row, so that
   * the state cannot change between the check and the write.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param string|null $etag
   *   The current entity tag; NULL if the resource does not exist.
   * @param int|null $lastModified
   *   When it last changed, as a Unix time, if known.
   *
   * @return int|null
   *   304 or 412 when the request must stop there; NULL to go ahead.
   */
  public static function evaluate(Request $request, ?string $etag, ?int $lastModified = NULL): ?int {
    $read = $request->isMethod('GET') || $request->isMethod('HEAD');
    $headers = $request->headers;

    // Step 1, then 2: If-Match, or failing that If-Unmodified-Since.
    if ($headers->has('If-Match')) {
      if (!self::matches((string) $headers->get('If-Match'), $etag, TRUE)) {
        return 412;
      }
    }
    elseif ($etag !== NULL && $lastModified !== NULL) {
      $since = self::date($headers->get('If-Unmodified-Since'));
      if ($since !== NULL && $lastModified > $since) {
        return 412;
      }
    }

    // Step 3, then 4: If-None-Match, or failing that If-Modified-Since.
    if ($headers->has('If-None-Match')) {
      if (self::matches((string) $headers->get('If-None-Match'), $etag, FALSE)) {
        return $read ? 304 : 412;
      }
    }
    elseif ($read && $etag !== NULL && $lastModified !== NULL) {
      $since = self::date($headers->get('If-Modified-Since'));
      if ($since !== NULL && $lastModified <= $since) {
        return 304;
      }
    }
    return NULL;
  }

  /**
   * Whether a field value of If-Match or If-None-Match matches.
   *
   * @param string $field
   *   Either "*" or a list of entity tags.
   * @param string|null $etag
   *   The current entity tag, unquoted; NULL if the resource does not exist.
   * @param bool $strong
   *   Whether to compare strongly (If-Match) or weakly (If-None-Match).
   */
  public static function matches(string $field, ?string $etag, bool $strong): bool {
    if ($etag === NULL) {
      return FALSE;
    }
    if (trim($field) === '*') {
      return TRUE;
    }
    preg_match_all('/(W\/)?"([^"]*)"/', $field, $tags, PREG_SET_ORDER);
    foreach ($tags as [, $weak, $opaque]) {
      if ($opaque === $etag && (!$strong || $weak === '')) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * An HTTP date as a Unix time.
   *
   * NULL if absent or invalid: RFC 9110 says to ignore invalid dates.
   */
  private static function date(?string $value): ?int {
    if ($value === NULL) {
      return NULL;
    }
    $date = \DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', trim($value), new \DateTimeZone('UTC'));
    return $date === FALSE ? NULL : $date->getTimestamp();
  }

}
