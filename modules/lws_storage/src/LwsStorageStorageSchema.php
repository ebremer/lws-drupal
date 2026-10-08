<?php

declare(strict_types=1);

namespace Drupal\lws_storage;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Makes storage slugs unique in the database, not only in validation.
 */
final class LwsStorageStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The schema of the entity's tables.
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE) {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $schema['lws_storage']['unique keys']['lws_storage__slug'] = ['slug'];
    return $schema;
  }

}
