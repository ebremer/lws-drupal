<?php

declare(strict_types=1);

namespace Drupal\lws_storage;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * The database keys that keep the containment hierarchy consistent.
 *
 * - (storage, path_hash): one resource per path in a storage, and so one root.
 * - (parent, name): one member per name in a container. Of two concurrent
 *   creates of the same name, the database rejects the second.
 *
 * Names and paths are stored case-sensitively (utf8mb4_bin on MySQL).
 */
final class LwsResourceStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The schema of the entity's tables.
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE) {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $schema['lws_resource']['unique keys']['lws_resource__storage_path'] = ['storage', 'path_hash'];
    $schema['lws_resource']['unique keys']['lws_resource__parent_name'] = ['parent', 'name'];
    return $schema;
  }

}
