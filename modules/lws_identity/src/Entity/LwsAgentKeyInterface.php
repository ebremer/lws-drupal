<?php

declare(strict_types=1);

namespace Drupal\lws_identity\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * A public key an agent authenticates with (lws10-authn-ssi-cid).
 *
 * Its owner is the user whose agent it belongs to. Its agent's document lists
 * it as a JsonWebKey verification method of the authentication relationship,
 * "{agent URI}#{key ID}".
 */
interface LwsAgentKeyInterface extends ContentEntityInterface, EntityOwnerInterface {

  /**
   * The key ID: the fragment of its verification method, and its JWK's "kid".
   */
  public function getKeyId(): string;

  /**
   * The public JWK, with its "kid".
   *
   * @return array<string, string>
   *   The JWK.
   */
  public function getJwk(): array;

  /**
   * When it was added, as a Unix time.
   */
  public function getCreatedTime(): int;

  /**
   * When it stops being usable, as a Unix time; NULL if it does not expire.
   */
  public function getExpires(): ?int;

}
