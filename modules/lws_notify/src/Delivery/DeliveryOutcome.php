<?php

declare(strict_types=1);

namespace Drupal\lws_notify\Delivery;

/**
 * What one delivery attempt came to, and so what happens next.
 *
 * As lws-server classifies them: retrying a 404 or a refused URL could
 * never succeed, while a 5xx, a 429 or no answer may be gone a minute later.
 */
enum DeliveryOutcome {

  // 2xx: done.
  case Delivered;

  // 5xx, 429, or no answer: tried again later.
  case Retry;

  // Any other answer, or a URL the guard refuses: counted as a failure, not
  // retried.
  case Failed;

  // 410 Gone: the inbox is finished, and the subscription is deactivated.
  case Gone;

  /**
   * The outcome of an HTTP status; 0 for no answer.
   */
  public static function forStatus(int $status): self {
    return match (TRUE) {
      $status >= 200 && $status < 300 => self::Delivered,
      $status === 410 => self::Gone,
      $status === 0, $status === 429, $status >= 500 => self::Retry,
      default => self::Failed,
    };
  }

}
