<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Token;

/**
 * An access token that failed validation.
 *
 * The message says why in words safe to show the client: it becomes the
 * "error_description" of the 401 challenge, and never repeats token contents.
 */
final class InvalidTokenException extends \RuntimeException {}
