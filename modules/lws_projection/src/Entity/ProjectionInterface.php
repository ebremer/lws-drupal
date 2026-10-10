<?php

declare(strict_types=1);

namespace Drupal\lws_projection\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * A projection of Drupal content into a read-only LWS storage.
 */
interface ProjectionInterface extends ConfigEntityInterface {

  /**
   * The slug of the storage it makes.
   */
  public function getSlug(): string;

  /**
   * Whether anyone may read the storage, without a token.
   */
  public function isPublic(): bool;

  /**
   * The content it projects.
   *
   * @return list<array{entity_type: string, bundle: string, type: string}>
   *   The entity types and bundles, each with the type of its resources as a
   *   URI, or "" for none.
   */
  public function getBundles(): array;

  /**
   * Sets the content it projects.
   *
   * @param list<array{entity_type: string, bundle: string, type: string}> $bundles
   *   The entity types and bundles, with the types of their resources.
   */
  public function setBundles(array $bundles): static;

  /**
   * Whether it projects a bundle.
   */
  public function covers(string $entityType, string $bundle): bool;

  /**
   * Whether it projects any bundle of an entity type.
   */
  public function coversType(string $entityType): bool;

  /**
   * The type of a bundle's resources, as a URI; NULL for none.
   */
  public function typeOf(string $entityType, string $bundle): ?string;

}
