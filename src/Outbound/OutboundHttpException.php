<?php

declare(strict_types=1);

namespace Drupal\lws\Outbound;

/**
 * An outbound request that was refused or failed.
 *
 * The message names the URL and the reason; it is meant for logs, not for
 * clients.
 */
final class OutboundHttpException extends \RuntimeException {}
