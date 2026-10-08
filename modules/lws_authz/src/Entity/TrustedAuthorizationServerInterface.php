<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * An authorization server whose access tokens storages accept.
 */
interface TrustedAuthorizationServerInterface extends ConfigEntityInterface {

  /**
   * The issuer identifier.
   *
   * It is the "iss" of the server's tokens, and the "as_uri" of 401
   * challenges for the storages that trust it.
   */
  public function getIssuer(): string;

  /**
   * The pinned JSON Web Key Set, as JSON; NULL to discover the keys.
   *
   * Without pinned keys, they are fetched from the "jwks_uri" of the server's
   * metadata at /.well-known/lws-configuration (LWS Core §5.2.2).
   */
  public function getJwks(): ?string;

}
