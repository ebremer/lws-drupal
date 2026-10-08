<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\file\FileInterface;
use Drupal\lws_storage\Linkset\UserMetadata;

/**
 * A container or data resource in an LWS storage.
 */
interface LwsResourceInterface extends ContentEntityInterface, EntityChangedInterface {

  /**
   * The longest path below the storage URI, in characters.
   */
  public const MAX_PATH_LENGTH = 2048;

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

  /**
   * The links clients manage: user types and other relations.
   */
  public function getUserMetadata(): UserMetadata;

  /**
   * Replaces the links clients manage.
   *
   * @param \Drupal\lws_storage\Linkset\UserMetadata $metadata
   *   The new links.
   * @param int $time
   *   When they changed.
   */
  public function setUserMetadata(UserMetadata $metadata, int $time): static;

  /**
   * When the links clients manage last changed, or else when it was created.
   */
  public function getMetadataChangedTime(): int;

  /**
   * The managed file with the current content of a data resource.
   *
   * @return \Drupal\file\FileInterface|null
   *   The file; NULL for a container.
   */
  public function getContentFile(): ?FileInterface;

  /**
   * The media type of a data resource's content; NULL for a container.
   */
  public function getMediaType(): ?string;

  /**
   * The size of a data resource's content in bytes; NULL for a container.
   */
  public function getSize(): ?int;

  /**
   * The strong entity tag of the resource's representation, unquoted.
   *
   * For a container, "c" and its version, which changes with its membership.
   * For a data resource, a digest of its content and media type, so that a
   * PUT of the same bytes keeps it (DESIGN.md §5.2).
   */
  public function getEtag(): string;

}
