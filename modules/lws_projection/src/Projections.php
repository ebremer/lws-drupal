<?php

declare(strict_types=1);

namespace Drupal\lws_projection;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\lws_projection\Entity\ProjectionInterface;

/**
 * The projections, and the storages they made.
 *
 * A projection manages only the storage it made itself, which State records
 * by its ID: a storage of the same slug made otherwise is never taken over,
 * made read-only or pruned.
 */
final class Projections {

  /**
   * The State key of the storages projections made.
   *
   * It holds storage IDs by projection ID.
   */
  public const STATE = 'lws_projection.storages';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StateInterface $state,
  ) {}

  /**
   * Every projection.
   *
   * @return array<string, \Drupal\lws_projection\Entity\ProjectionInterface>
   *   The projections, by ID.
   */
  public function all(): array {
    return array_filter(
      $this->entityTypeManager->getStorage('lws_projection')->loadMultiple(),
      static fn ($projection): bool => $projection instanceof ProjectionInterface,
    );
  }

  /**
   * A projection by ID.
   */
  public function load(string $id): ?ProjectionInterface {
    $projection = $this->entityTypeManager->getStorage('lws_projection')->load($id);
    return $projection instanceof ProjectionInterface ? $projection : NULL;
  }

  /**
   * The projections of a bundle.
   *
   * @return list<\Drupal\lws_projection\Entity\ProjectionInterface>
   *   The projections.
   */
  public function covering(string $entityType, string $bundle): array {
    return array_values(array_filter($this->all(), static fn (ProjectionInterface $projection): bool => $projection->covers($entityType, $bundle)));
  }

  /**
   * The ID of the storage a projection made, if it made one.
   */
  public function storageId(string $projectionId): ?int {
    $storages = $this->storages();
    return isset($storages[$projectionId]) ? (int) $storages[$projectionId] : NULL;
  }

  /**
   * Records the storage a projection made, or that it has none.
   */
  public function setStorageId(string $projectionId, ?int $storageId): void {
    $storages = $this->storages();
    if ($storageId === NULL) {
      unset($storages[$projectionId]);
    }
    else {
      $storages[$projectionId] = $storageId;
    }
    $this->state->set(self::STATE, $storages);
  }

  /**
   * Whether a storage is one a projection made, and keeps.
   */
  public function isProjected(int $storageId): bool {
    return in_array($storageId, array_map('intval', $this->storages()), TRUE);
  }

  /**
   * The storages projections made.
   *
   * @return array<string, int>
   *   Storage IDs by projection ID.
   */
  private function storages(): array {
    $storages = $this->state->get(self::STATE, []);
    return is_array($storages) ? $storages : [];
  }

}
