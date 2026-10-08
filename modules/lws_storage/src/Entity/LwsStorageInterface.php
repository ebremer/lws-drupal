<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;

/**
 * An LWS storage: a hierarchy of resources under one storage URI.
 */
interface LwsStorageInterface extends ContentEntityInterface, EntityChangedInterface {

  /**
   * The URI segment that names the storage.
   */
  public function getSlug(): string;

  /**
   * The storage controllers: agents with full control of the storage.
   *
   * @return list<string>
   *   Agent URIs.
   */
  public function getControllers(): array;

  /**
   * The ID of the Drupal user who administers the storage, if any.
   */
  public function getOwnerId(): ?int;

  /**
   * The trusted authorization server of the storage, by ID.
   *
   * @return string|null
   *   The ID of an lws_trusted_as entity; NULL for the site's default.
   */
  public function getAuthorizationServerId(): ?string;

  /**
   * The most content the storage may hold, in bytes; NULL for no limit.
   */
  public function getQuotaBytes(): ?int;

  /**
   * The content the storage holds, in bytes.
   */
  public function getUsedBytes(): int;

  /**
   * The members on one page of a listing; NULL for the site default.
   */
  public function getPageSize(): ?int;

  /**
   * Whether the storage serves requests; a blocked storage answers 503.
   */
  public function isEnabled(): bool;

}
