<?php

declare(strict_types=1);

namespace Drupal\lws_notify;

/**
 * A subscription request that cannot be accepted as it is (422).
 *
 * The message says why, for the client.
 */
final class InvalidSubscriptionException extends \InvalidArgumentException {}
