<?php

declare(strict_types=1);

namespace Drupal\lws_authz\AccessService;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Indexes access requests and grants by storage and kind, as they are listed.
 */
final class LwsAccessRecordStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The schema of the entity's tables.
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE) {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $schema['lws_access']['indexes']['lws_access__storage_kind'] = ['storage', 'kind'];
    return $schema;
  }

}
