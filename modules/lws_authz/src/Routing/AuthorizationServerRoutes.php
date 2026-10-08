<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Routing;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\lws\Routing\LwsUrlParser;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * The routes of the authorization server's endpoints, under the LWS prefix.
 *
 * The prefix is configurable, so these routes are built; a change of prefix
 * rebuilds the router.
 */
final class AuthorizationServerRoutes implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(
    private readonly LwsUrlParser $parser,
  ) {}

  /**
   * The token endpoint and the key set.
   */
  public function routes(): RouteCollection {
    $prefix = $this->parser->prefix();
    $controller = '\Drupal\lws_authz\Controller\AuthorizationServerController';
    $routes = new RouteCollection();
    $routes->add('lws_authz.token', new Route(
      $prefix . '/oauth/token',
      ['_controller' => $controller . '::token'],
      ['_access' => 'TRUE'],
      ['no_cache' => TRUE],
      methods: ['POST'],
    ));
    $routes->add('lws_authz.jwks', new Route(
      $prefix . '/oauth/jwks',
      ['_controller' => $controller . '::jwks'],
      ['_access' => 'TRUE'],
      ['no_cache' => TRUE],
      methods: ['GET', 'HEAD'],
    ));
    return $routes;
  }

}
