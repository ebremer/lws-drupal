<?php

declare(strict_types=1);

namespace Drupal\lws_notify;

/**
 * An agent holds as many subscriptions at a storage as it may (429).
 */
final class SubscriptionLimitException extends \RuntimeException {}
