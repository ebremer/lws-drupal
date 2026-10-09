<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Policy;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;
use Drupal\Core\Field\FieldStorageDefinitionInterface;

/**
 * Indexes access policies by storage, which every decision looks them up by.
 *
 * The time a policy's dateTime constraints end it may lie past 2038, beyond a
 * 32-bit integer, so not_after is a big one: MySQL and MariaDB refuse such a
 * time for a normal integer column (SQLite stores 64 bits either way).
 */
final class LwsPolicyStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The schema of the entity's tables.
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE) {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $schema['lws_policy']['indexes']['lws_policy__storage'] = ['storage'];
    $schema['lws_policy']['indexes']['lws_policy__not_after'] = ['not_after'];
    return $schema;
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Field\FieldStorageDefinitionInterface $storage_definition
   *   The field.
   * @param string $table_name
   *   The table.
   * @param array<string, string> $column_mapping
   *   The field's columns.
   *
   * @return array<string, mixed>
   *   The schema of the field's columns.
   */
  protected function getSharedTableFieldSchema(FieldStorageDefinitionInterface $storage_definition, $table_name, array $column_mapping) {
    $schema = parent::getSharedTableFieldSchema($storage_definition, $table_name, $column_mapping);
    if ($storage_definition->getName() === 'not_after') {
      $schema['fields']['not_after']['size'] = 'big';
    }
    return $schema;
  }

}
