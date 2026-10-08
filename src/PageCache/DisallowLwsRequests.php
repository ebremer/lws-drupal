<?php

declare(strict_types=1);

namespace Drupal\lws\PageCache;

use Drupal\Core\PageCache\RequestPolicyInterface;
use Drupal\lws\Routing\LwsUrlParser;
use Symfony\Component\HttpFoundation\Request;

/**
 * Keeps LWS requests and bearer-token requests out of the page cache.
 *
 * The page cache treats every request without a session cookie as anonymous.
 * A request with a bearer token has no session cookie, so without this policy
 * it could be served a cached anonymous response, or have its own response
 * cached for anonymous users. Core's basic_auth module has the same policy for
 * its credentials.
 */
final class DisallowLwsRequests implements RequestPolicyInterface {

  public function __construct(
    private readonly LwsUrlParser $parser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function check(Request $request) {
    if ($this->parser->parse($request->getPathInfo()) !== NULL) {
      return self::DENY;
    }
    if (preg_match('/^(?:Bearer|DPoP)\s/i', (string) $request->headers->get('Authorization'))) {
      return self::DENY;
    }
    return NULL;
  }

}
