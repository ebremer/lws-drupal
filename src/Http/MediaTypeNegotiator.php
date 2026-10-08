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
   * @param string|null $accept
   *   The Accept header value.
   * @param list<string> $offered
   *   Media types without parameters, in the server's order of preference.
   *
   * @return \Drupal\lws\Http\NegotiatedType|null
   *   The chosen type, or NULL if the request accepts none of them.
   */
  public static function negotiate(?string $accept, array $offered): ?NegotiatedType {
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
      $params = [];
      foreach ($ranges as $range) {
        [$rangeMain, $rangeSub] = explode('/', strtolower($range->getValue()), 2) + [1 => ''];
        $rangeParams = array_diff_key($range->getAttributes(), ['q' => TRUE]);
        $match = match (TRUE) {
          $rangeMain === $main && $rangeSub === $sub => 2 + ($rangeParams === [] ? 0 : 1),
          $rangeMain === $main && $rangeSub === '*' => 1,
          $rangeMain === '*' && $rangeSub === '*' => 0,
          default => -1,
        };
        if ($match > $specificity) {
          $specificity = $match;
          $quality = $range->getQuality();
          $params = $match === 3 ? $rangeParams : [];
        }
      }
      if ($quality > $bestQuality) {
        $best = new NegotiatedType($type, $params);
        $bestQuality = $quality;
      }
    }
    return $best;
  }

}
