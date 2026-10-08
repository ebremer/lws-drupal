<?php

declare(strict_types=1);

namespace Drupal\lws_authz\AuthenticationSuite;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\lws_authz\Attribute\LwsAuthenticationSuite;

/**
 * Manages authentication suite plugins.
 *
 * Suites live in Plugin/LwsAuthenticationSuite. Those listed in
 * lws_authz.settings:suites are enabled: the token endpoint accepts their
 * credentials, and the metadata advertises them.
 */
final class AuthenticationSuiteManager extends DefaultPluginManager {

  /**
   * Constructs the manager.
   *
   * @param \Traversable<string, string> $namespaces
   *   The namespaces to look for plugins in, with their directories.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   The cache of plugin definitions.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler, for the alter hook.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, for the enabled suites.
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct('Plugin/LwsAuthenticationSuite', $namespaces, $module_handler, AuthenticationSuiteInterface::class, LwsAuthenticationSuite::class);
    $this->alterInfo('lws_authentication_suite_info');
    $this->setCacheBackend($cache_backend, 'lws_authentication_suite_plugins');
  }

  /**
   * The enabled suites.
   *
   * @return array<string, \Drupal\lws_authz\AuthenticationSuite\AuthenticationSuiteInterface>
   *   The suites, by token type.
   */
  public function enabled(): array {
    $enabled = [];
    foreach ((array) $this->configFactory->get('lws_authz.settings')->get('suites') as $id) {
      if (is_string($id) && $this->hasDefinition($id)) {
        $suite = $this->createInstance($id);
        assert($suite instanceof AuthenticationSuiteInterface);
        $enabled[$suite->tokenType()] = $suite;
      }
    }
    return $enabled;
  }

  /**
   * The enabled suite for a token type, if there is one.
   */
  public function forTokenType(string $tokenType): ?AuthenticationSuiteInterface {
    return $this->enabled()[$tokenType] ?? NULL;
  }

}
