<?php

declare(strict_types=1);

namespace Drupal\lws\Routing;

use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Routes the LWS URL space to its internal paths.
 *
 * Works like core's PathProcessorFiles for /system/files/…: Drupal's router
 * cannot match a parameter that contains "/", so every URL under the prefix
 * is mapped to one fixed internal path per area. The target itself is not
 * passed along on the request: the router caches the processed path per URL
 * and skips path processors on a cache hit, so LwsRouteEnhancer parses the
 * URL again on every request.
 */
final class LwsPathProcessor implements InboundPathProcessorInterface {

  public function __construct(
    private readonly LwsUrlParser $parser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function processInbound($path, Request $request) {
    $raw = $request->getPathInfo();
    // The router passes the request's own path, right-trimmed. Any other path
    // is a lookup for something else, such as path validation, which the LWS
    // URL space takes no part in.
    if ($path !== ($raw === '/' ? $raw : rtrim($raw, '/'))) {
      return $path;
    }
    return $this->parser->parse($raw)?->area->internalPath() ?? $path;
  }

}
