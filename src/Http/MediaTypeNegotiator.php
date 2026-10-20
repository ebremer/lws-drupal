<?php

declare(strict_types=1);

namespace Drupal\lws\Http;

use Symfony\Component\HttpFoundation\AcceptHeader;

/**
 * Chooses a response media type from a request's Accept header (RFC 9110).
 */
final class MediaTypeNegotiator {

  /**
   * Chooses one of the offered media types.
   *
   * Each offered type takes the quality of the most specific media range that
   * matches it; the highest quality wins, and ties go to the earlier offer. No
   * Accept header, or an empty one, selects the first offer.
   *
   * A range can ask for a type in a profile, with a "profile" parameter whose
   * value is a space-separated list of profile URIs (RFC 6906 §3). For a type
   * in $profiles, such a range matches only if its list names one of the
   * profiles the server produces the type in, and the chosen type then
   * carries that profile; a range naming none of them does not match the
   * type at all, so the other ranges decide. A server must not select a type
   * in a profile it cannot produce (LWS Core, Content Negotiation). For other
   * types, the parameter plays no part.
   *
   * @param string|null $accept
   *   The Accept header value.
   * @param list<string> $offered
   *   Media types without parameters, in the server's order of preference.
   * @param array<string, list<string>> $profiles
   *   For offered types that take a profile parameter, such as
   *   application/ld+json, the profiles the server produces them in, in its
   *   order of preference; an empty list for none.
   *
   * @return \Drupal\lws\Http\NegotiatedType|null
   *   The chosen type, or NULL if the request accepts none of them.
   */
  public static function negotiate(?string $accept, array $offered, array $profiles = []): ?NegotiatedType {
    $ranges = AcceptHeader::fromString($accept ?? '')->all();
    if ($ranges === []) {
      return new NegotiatedType($offered[0]);
    }
    $best = NULL;
    $bestQuality = 0.0;
    foreach ($offered as $type) {
      [$main, $sub] = explode('/', strtolower($type), 2);
      $quality = 0.0;
      $specificity = -1;
      $profile = NULL;
      foreach ($ranges as $range) {
        [$rangeMain, $rangeSub] = explode('/', strtolower($range->getValue()), 2) + [1 => ''];
        $rangeParams = array_diff_key($range->getAttributes(), ['q' => TRUE]);
        $match = match (TRUE) {
          $rangeMain === $main && $rangeSub === $sub => 2 + ($rangeParams === [] ? 0 : 1),
          $rangeMain === $main && $rangeSub === '*' => 1,
          $rangeMain === '*' && $rangeSub === '*' => 0,
          default => -1,
        };
        $rangeProfile = NULL;
        if ($match === 3 && isset($profiles[$type], $rangeParams['profile'])) {
          $rangeProfile = self::profile($rangeParams['profile'], $profiles[$type]);
          if ($rangeProfile === NULL) {
            continue;
          }
        }
        if ($match > $specificity) {
          $specificity = $match;
          $quality = $range->getQuality();
          $profile = $rangeProfile;
        }
      }
      if ($quality > $bestQuality) {
        $best = new NegotiatedType($type, $profile);
        $bestQuality = $quality;
      }
    }
    return $best;
  }

  /**
   * The first of the server's profiles that a profile parameter names.
   *
   * @param mixed $value
   *   The parameter's value, unquoted: profile URIs separated by spaces.
   * @param list<string> $produced
   *   The profiles the server produces the type in.
   */
  private static function profile(mixed $value, array $produced): ?string {
    // A parameter without a value comes as TRUE, and names no profile.
    $requested = is_string($value) ? preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY) : FALSE;
    if (!is_array($requested)) {
      return NULL;
    }
    foreach ($produced as $profile) {
      if (in_array($profile, $requested, TRUE)) {
        return $profile;
      }
    }
    return NULL;
  }

}
