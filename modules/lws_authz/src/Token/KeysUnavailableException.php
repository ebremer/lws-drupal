<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Token;

/**
 * The keys of an authorization server could not be obtained.
 *
 * The message is meant for logs, not for clients.
 */
final class KeysUnavailableException extends \RuntimeException {}
