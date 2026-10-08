<?php

declare(strict_types=1);

namespace Drupal\lws_authz;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lws\Storage\StorageRef;
use Drupal\lws_authz\Entity\TrustedAuthorizationServerInterface;

/**
 * Finds the authorization server each storage trusts.
 */
final class AuthorizationServers {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * The server a storage trusts: its own, or the site's default.
   *
   * @return \Drupal\lws_authz\Entity\TrustedAuthorizationServerInterface|null
   *   The server; NULL if there is none, or it is disabled.
   */
  public function forStorage(StorageRef $storage): ?TrustedAuthorizationServerInterface {
    $id = $storage->authorizationServer ?? (string) $this->configFactory->get('lws_authz.settings')->get('authorization_server');
    return $id === '' ? NULL : $this->load($id);
  }

  /**
   * The site's default server, if it has one.
   */
  public function default(): ?TrustedAuthorizationServerInterface {
    $id = (string) $this->configFactory->get('lws_authz.settings')->get('authorization_server');
    return $id === '' ? NULL : $this->load($id);
  }

  /**
   * An enabled server by ID.
   */
  public function load(string $id): ?TrustedAuthorizationServerInterface {
    $server = $this->entityTypeManager->getStorage('lws_trusted_as')->load($id);
    return $server instanceof TrustedAuthorizationServerInterface && $server->status() ? $server : NULL;
  }

}
