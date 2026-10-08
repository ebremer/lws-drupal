<?php

declare(strict_types=1);

namespace Drupal\lws\Routing;

use Drupal\Core\Routing\EnhancerInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Route;

/**
 * Gives LWS controllers the target of the request as $lws_target.
 *
 * The URL is parsed here, after routing, rather than in LwsPathProcessor:
 * route enhancers run on every request, while path processors are skipped
 * when the router's per-URL cache already holds the processed path.
 */
final class LwsRouteEnhancer implements EnhancerInterface {

  public function __construct(
    private readonly LwsUrlParser $parser,
  ) {}

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $defaults
   *   The route defaults.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return array<string, mixed>
   *   The defaults, with 'lws_target' added on LWS routes.
   */
  public function enhance(array $defaults, Request $request) {
    $route = $defaults[RouteObjectInterface::ROUTE_OBJECT] ?? NULL;
    if (!$route instanceof Route || !$route->hasOption('_lws')) {
      return $defaults;
    }
    $target = $this->parser->parse($request->getPathInfo());
    // The internal path was requested directly, not through the URL space.
    if ($target === NULL || $target->area->internalPath() !== $route->getPath()) {
      throw new NotFoundHttpException();
    }
    $defaults['lws_target'] = $target;
    return $defaults;
  }

}
