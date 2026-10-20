<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Routing;

use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Ebremer\Lws\Vocabulary;
use Symfony\Component\HttpFoundation\Request;

/**
 * Routes the URLs of protected resource metadata to their one route.
 *
 * The metadata of a resource is at /.well-known/oauth-protected-resource
 * followed by the resource's path (RFC 9728 §3.1), at any depth, which
 * Drupal's router cannot match with a parameter. As LwsPathProcessor does for
 * the LWS URL space, every such path is mapped to the route's own path; the
 * controller reads the resource's path from the request, raw.
 */
final class ResourceMetadataPathProcessor implements InboundPathProcessorInterface {

  /**
   * {@inheritdoc}
   */
  public function processInbound($path, Request $request) {
    $raw = $request->getPathInfo();
    // Only the request's own path, right-trimmed as the router passes it; any
    // other path is a lookup for something else, such as path validation.
    if ($path !== ($raw === '/' ? $raw : rtrim($raw, '/'))) {
      return $path;
    }
    return str_starts_with($raw, Vocabulary::WELL_KNOWN_OAUTH_PROTECTED_RESOURCE . '/') ? Vocabulary::WELL_KNOWN_OAUTH_PROTECTED_RESOURCE : $path;
  }

}
