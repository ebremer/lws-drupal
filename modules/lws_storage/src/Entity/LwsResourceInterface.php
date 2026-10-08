<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;

/**
 * A container or data resource in an LWS storage.
 */
interface LwsResourceInterface extends ContentEntityInterface, EntityChangedInterface {

  /**
   * The ID of the storage the resource belongs to.
   */
  public function getLwsStorageId(): int;

  /**
   * The containing resource, or NULL for the storage root.
   */
  public function getParent(): ?LwsResourceInterface;

  /**
   * The stored name: the decoded last segment, with a slash for containers.
   */
  public function getName(): string;

  /**
   * The decoded path below the storage URI, such as "root/notes/a.txt".
   */
  public function getPath(): string;

  /**
   * The decoded names from the root container down.
   *
   * @return list<string>
   *   Names without slashes, starting with "root".
   */
  public function getSegments(): array;

  /**
   * Whether the resource is a container.
   */
  public function isContainer(): bool;

  /**
   * Whether the resource is the storage root container.
   */
  public function isRoot(): bool;

  /**
   * The version of the resource; container ETags derive from it.
   *
   * It changes with the resource and, for a container, with its membership.
   */
  public function getVersion(): int;

}
