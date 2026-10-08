<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Cid;

use Ebremer\Lws\Auth\VerificationKey;

/**
 * A verification method a subject may authenticate with (CID 1.0 §2.2).
 */
final class VerificationMethod {

  /**
   * Constructs a verification method.
   *
   * @param string $id
   *   Its identifier, absolute.
   * @param \Ebremer\Lws\Auth\VerificationKey $key
   *   Its public key.
   * @param string|null $keyId
   *   The "kid" of its JSON Web Key, if it has one.
   * @param int|null $revoked
   *   When it was revoked, as a Unix time.
   * @param int|null $expires
   *   When it expires, as a Unix time.
   */
  public function __construct(
    public readonly string $id,
    public readonly VerificationKey $key,
    public readonly ?string $keyId = NULL,
    public readonly ?int $revoked = NULL,
    public readonly ?int $expires = NULL,
  ) {}

  /**
   * Why it cannot be used at a time, or NULL if it can.
   */
  public function inactiveReason(int $now): ?string {
    if ($this->revoked !== NULL && $now >= $this->revoked) {
      return 'it was revoked';
    }
    if ($this->expires !== NULL && $now >= $this->expires) {
      return 'it has expired';
    }
    return NULL;
  }

}
