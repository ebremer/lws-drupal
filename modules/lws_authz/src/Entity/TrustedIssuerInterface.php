<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * An OpenID Provider whose ID Tokens this site exchanges for access tokens.
 *
 * With none configured for an issuer, trust comes from each subject's own
 * controlled identifier document (lws10-authn-openid §5). Configuring one is
 * a pre-existing trust relationship, which may pin the provider's keys, waive
 * the audience this site would otherwise require, or trust it for any subject.
 */
interface TrustedIssuerInterface extends ConfigEntityInterface {

  /**
   * The issuer identifier: the "iss" of its ID Tokens.
   */
  public function getIssuer(): string;

  /**
   * The pinned JSON Web Key Set, as JSON; NULL to discover the keys.
   */
  public function getJwks(): ?string;

  /**
   * Whether its ID Tokens must name this authorization server in "aud".
   */
  public function requiresAsAudience(): bool;

  /**
   * Whether each subject's document must name it as an OpenID Provider.
   *
   * Without it, the provider is trusted to assert any subject.
   */
  public function verifiesSubject(): bool;

}
