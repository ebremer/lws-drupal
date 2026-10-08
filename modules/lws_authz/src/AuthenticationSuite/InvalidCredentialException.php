<?php

declare(strict_types=1);

namespace Drupal\lws_authz\AuthenticationSuite;

/**
 * Thrown when a subject token is not a valid authentication credential.
 *
 * The message says why, and may be shown to the client.
 */
final class InvalidCredentialException extends \RuntimeException {}
