<?php

declare(strict_types=1);

namespace Drupal\lws_authz\AccessService;

/**
 * Thrown when an agent may not submit what it submitted; the message says why.
 */
final class AccessDeniedException extends \RuntimeException {}
