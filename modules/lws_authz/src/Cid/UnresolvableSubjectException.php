<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Cid;

/**
 * Thrown when a subject identifier cannot be dereferenced to its document.
 *
 * The message may be shown to the client.
 */
final class UnresolvableSubjectException extends \RuntimeException {}
