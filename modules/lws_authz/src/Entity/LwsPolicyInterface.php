<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\lws_authz\Policy\AccessPolicy;
use Drupal\user\EntityOwnerInterface;

/**
 * A stored access policy of a storage (DESIGN.md §6.5).
 *
 * Its owner is the Drupal user who made it, if any.
 */
interface LwsPolicyInterface extends ContentEntityInterface, EntityOwnerInterface {

  /**
   * The ID of the storage it applies in.
   */
  public function getStorageId(): int;

  /**
   * Where it comes from: "admin", or "grant:{id}" for an access grant's.
   */
  public function getSource(): string;

  /**
   * The policy.
   */
  public function toAccessPolicy(): AccessPolicy;

  /**
   * Sets the policy.
   *
   * @return $this
   */
  public function setAccessPolicy(AccessPolicy $policy): static;

  /**
   * The latest time it can permit anything, if a constraint ends it.
   */
  public function getNotAfter(): ?int;

}
