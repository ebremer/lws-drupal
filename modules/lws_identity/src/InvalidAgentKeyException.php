<?php

declare(strict_types=1);

namespace Drupal\lws_identity;

/**
 * A key that cannot be added to an agent; the message says why.
 */
final class InvalidAgentKeyException extends \InvalidArgumentException {
}
