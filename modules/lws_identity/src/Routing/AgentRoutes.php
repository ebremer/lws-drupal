<?php

declare(strict_types=1);

namespace Drupal\lws_identity\Routing;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\lws\Routing\LwsUrlParser;
use Drupal\lws_identity\AgentUris;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * The route of agent documents, {prefix}/agents/{uuid}.
 *
 * The prefix is configurable, so the route is built; a change of prefix
 * rebuilds the router.
 */
final class AgentRoutes implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(
    private readonly LwsUrlParser $parser,
  ) {}

  /**
   * The agent document route.
   */
  public function routes(): RouteCollection {
    $routes = new RouteCollection();
    $routes->add('lws_identity.agent', new Route(
      $this->parser->prefix() . '/' . AgentUris::SEGMENT . '/{uuid}',
      ['_controller' => '\Drupal\lws_identity\Controller\AgentDocumentController::document'],
      // Public: verifiers dereference agent URIs without credentials. The
      // controller answers 404 for a UUID no agent has.
      ['_access' => 'TRUE', 'uuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'],
      ['no_cache' => TRUE],
      methods: ['GET', 'HEAD'],
    ));
    return $routes;
  }

}
