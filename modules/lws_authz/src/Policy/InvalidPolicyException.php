<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Policy;

/**
 * Thrown when an access policy is not valid; the message says why.
 */
final class InvalidPolicyException extends \InvalidArgumentException {}
