<?php

declare(strict_types=1);

namespace Drupal\lws\Access;

/**
 * The answer of the policy decision point.
 */
enum Decision {

  case Permit;
  case Deny;

  /**
   * Whether the operation may go ahead.
   */
  public function isPermitted(): bool {
    return $this === self::Permit;
  }

}
