<?php

declare(strict_types=1);

namespace Drupal\lws_authz;

/**
 * An authorization server whose access tokens a storage accepts.
 *
 * It is either this site's own, or a trusted external one.
 */
interface AuthorizationServerInterface {

  /**
   * Its ID: "local" for this site's own, else a trusted server's.
   *
   * @return string|int|null
   *   The ID.
   */
  public function id();

  /**
   * Its name.
   *
   * @return string|\Drupal\Core\StringTranslation\TranslatableMarkup|null
   *   The name.
   */
  public function label();

  /**
   * The issuer identifier.
   *
   * It is the "iss" of the server's tokens, and the "as_uri" of 401
   * challenges for the storages that trust it.
   */
  public function getIssuer(): string;

  /**
   * The JSON Web Key Set of its signing keys, as JSON; NULL to discover them.
   *
   * Without it, the keys are fetched from the "jwks_uri" of the server's
   * metadata at /.well-known/lws-configuration (LWS Core §5.2.2).
   */
  public function getJwks(): ?string;

}
