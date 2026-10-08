<?php

declare(strict_types=1);

namespace Drupal\lws\Controller;

use Drupal\lws\Http\LwsHttpException;
use Drupal\lws\Routing\LwsTarget;

/**
 * Answers for paths in the LWS URL space at which nothing can exist.
 */
final class UnknownController {

  /**
   * Throws the 404 for an unknown path.
   */
  public function unknown(LwsTarget $lws_target): never {
    throw LwsHttpException::forUnaddressable($lws_target);
  }

}
