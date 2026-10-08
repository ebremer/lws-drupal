<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * An access request or access grant of a storage (LWS Core §11).
 *
 * Its owner is the Drupal user who made it, as by approving a request in
 * the administration pages; 0 when an agent made it over LWS.
 */
interface LwsAccessRecordInterface extends ContentEntityInterface, EntityOwnerInterface {

  /**
   * The kind of an access request.
   */
  public const REQUEST = 'request';

  /**
   * The kind of an access grant.
   */
  public const GRANT = 'grant';

  /**
   * The kind: "request" or "grant".
   */
  public function getKind(): string;

  /**
   * The ID of the storage it is for.
   */
  public function getStorageId(): int;

  /**
   * The agent who submitted it over LWS, if one did.
   */
  public function getCreator(): ?string;

  /**
   * The client the agent submitted it with, if one did.
   */
  public function getClient(): ?string;

  /**
   * The inbox for notifications about it, if it names one.
   */
  public function getInbox(): ?string;

  /**
   * The document, as it is served: the one submitted, with its "id".
   *
   * @return array<string, mixed>
   *   The document.
   */
  public function getDocument(): array;

}
