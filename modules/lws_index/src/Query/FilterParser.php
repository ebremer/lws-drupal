<?php

declare(strict_types=1);

namespace Drupal\lws_index\Query;

use Drupal\lws\Http\LwsHttpException;
use Drupal\lws_index\Indexer;

/**
 * Parses application/lws-query+json filters (lws10-index).
 *
 * A filter is a JSON object. Each member, but those whose names begin with
 * "@", constrains one relation, "type" or another: its value is an array of
 * groups, ANDed; a group is an IRI, or a non-empty array of IRIs, ORed. A
 * member whose value is an empty array constrains nothing.
 */
final class FilterParser {

  /**
   * The most groups a filter may have.
   */
  public const MAX_GROUPS = 32;

  /**
   * The most IRIs a filter may hold, in all its groups.
   */
  public const MAX_IRIS = 64;

  /**
   * The most bytes its IRIs and relations may take, in all.
   *
   * Every page link of the results carries the filter, and a URL has to fit
   * in a request line (Apache refuses those over 8190 bytes).
   */
  public const MAX_BYTES = 4096;

  /**
   * An absolute IRI, with an optional fragment (RFC 3987 §2.2).
   *
   * A scheme, then IRI characters: unreserved and reserved ASCII characters,
   * percent-encodings and the Unicode ranges RFC 3987 allows, with at most
   * one "#". Spaces, controls and <>"{}|\^` are not IRI characters.
   */
  private const IRI = '/^[A-Za-z][A-Za-z0-9+.\-]*:(?:[A-Za-z0-9\-._~!$&\'()*+,;=:@\/?\[\]]|%[0-9A-Fa-f]{2}|[\x{A0}-\x{D7FF}\x{E000}-\x{FDCF}\x{FDF0}-\x{FFEF}\x{10000}-\x{10FFFD}])*(?:#(?:[A-Za-z0-9\-._~!$&\'()*+,;=:@\/?]|%[0-9A-Fa-f]{2}|[\x{A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}\x{10000}-\x{EFFFD}])*)?$/u';

  /**
   * Parses a filter.
   *
   * @param string $body
   *   The request body.
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   400 for a body that is not a filter; 422 for a filter more complex
   *   than this server supports, which is never narrowed instead.
   */
  public static function parse(string $body): TypeFilter {
    try {
      $document = json_decode($body, FALSE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      throw LwsHttpException::badRequest('The filter is not well-formed JSON.');
    }
    if (!$document instanceof \stdClass) {
      throw LwsHttpException::badRequest('A filter is a JSON object.');
    }
    $groups = [];
    foreach (get_object_vars($document) as $key => $value) {
      $key = (string) $key;
      // JSON-LD keywords, such as @context, are not constraints.
      if (str_starts_with($key, '@')) {
        continue;
      }
      if (!is_array($value)) {
        throw LwsHttpException::badRequest(sprintf('The value of "%s" must be an array of groups.', self::excerpt($key)));
      }
      // Indexer::normalizeRel() refuses only relations it cannot hold, which
      // nothing declares: those match nothing, as unindexed ones do.
      $rel = Indexer::normalizeRel($key) ?? "\0" . $key;
      foreach ($value as $group) {
        $hrefs = match (TRUE) {
          is_string($group) => [$group],
          is_array($group) && $group === [] => throw LwsHttpException::badRequest(sprintf('"%s" has an empty group, which could match nothing.', self::excerpt($key))),
          is_array($group) && count(array_filter($group, 'is_string')) === count($group) => $group,
          default => throw LwsHttpException::badRequest(sprintf('Each group of "%s" must be an IRI or a non-empty array of IRIs.', self::excerpt($key))),
        };
        foreach ($hrefs as $href) {
          if (!self::isIri($href)) {
            throw LwsHttpException::badRequest(sprintf('"%s" is not an absolute IRI.', self::excerpt($href)));
          }
        }
        $groups[] = ['rel' => $rel, 'hrefs' => array_values($hrefs)];
      }
    }
    $filter = new TypeFilter($groups);
    self::checkComplexity($filter);
    return $filter;
  }

  /**
   * Whether a value is an absolute IRI, optionally with a fragment.
   */
  public static function isIri(string $value): bool {
    return preg_match(self::IRI, $value) === 1;
  }

  /**
   * Refuses a filter more complex than this server supports.
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   422.
   */
  private static function checkComplexity(TypeFilter $filter): void {
    $iris = 0;
    $bytes = 0;
    foreach ($filter->groups as $group) {
      $iris += count($group['hrefs']);
      $bytes += strlen($group['rel']) + array_sum(array_map('strlen', $group['hrefs']));
    }
    if (count($filter->groups) > self::MAX_GROUPS || $iris > self::MAX_IRIS || $bytes > self::MAX_BYTES) {
      throw LwsHttpException::unprocessable(sprintf(
        'The filter is more complex than this server supports: it takes at most %d groups, of %d IRIs and %d bytes in all.',
        self::MAX_GROUPS,
        self::MAX_IRIS,
        self::MAX_BYTES,
      ));
    }
  }

  /**
   * A value as an error message may quote it.
   */
  private static function excerpt(string $value): string {
    return mb_strlen($value) > 100 ? mb_substr($value, 0, 100) . '…' : $value;
  }

}
