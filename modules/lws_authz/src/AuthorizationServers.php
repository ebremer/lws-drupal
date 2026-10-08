<?php

declare(strict_types=1);

namespace Drupal\lws_authz;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lws\Storage\StorageRef;
use Drupal\lws_authz\Entity\TrustedAuthorizationServerInterface;
use Drupal\lws_authz\Server\LocalAuthorizationServer;

/**
 * Finds the authorization server each storage trusts.
 *
 * That is this site's own ("local"), or a trusted external server.
 */
final class AuthorizationServers {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LocalAuthorizationServer $local,
  ) {}

  /**
   * The server a storage trusts: its own, or the site's default.
   *
   * @return \Drupal\lws_authz\AuthorizationServerInterface|null
   *   The server; NULL if it is missing, disabled or unavailable.
   */
  public function forStorage(StorageRef $storage): ?AuthorizationServerInterface {
    return $this->load($storage->authorizationServer ?? $this->defaultId());
  }

  /**
   * The ID of the site's default server.
   */
  public function defaultId(): string {
    $id = (string) $this->configFactory->get('lws_authz.settings')->get('authorization_server');
    return $id === '' ? LocalAuthorizationServer::ID : $id;
  }

  /**
   * The site's default server, if it is usable.
   */
  public function default(): ?AuthorizationServerInterface {
    return $this->load($this->defaultId());
  }

  /**
   * A server by ID, if it is usable.
   *
   * This site's own is usable when it has a key directory; a trusted one when
   * it is enabled.
   */
  public function load(string $id): ?AuthorizationServerInterface {
    if ($id === LocalAuthorizationServer::ID) {
      return $this->local->isAvailable() ? $this->local : NULL;
    }
    $server = $this->entityTypeManager->getStorage('lws_trusted_as')->load($id);
    return $server instanceof TrustedAuthorizationServerInterface && $server->status() ? $server : NULL;
  }

  /**
   * Whether a server ID names a server, usable or not.
   */
  public function exists(string $id): bool {
    return $id === LocalAuthorizationServer::ID || $this->entityTypeManager->getStorage('lws_trusted_as')->load($id) !== NULL;
  }

}
