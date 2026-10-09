<?php

declare(strict_types=1);

namespace Drupal\lws_notify;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;
use Drupal\Core\Field\FieldStorageDefinitionInterface;

/**
 * Indexes subscriptions as they are looked up: by storage and by agent.
 *
 * Without a longest lifetime (lws_notify.settings:limits.lifetime 0), an agent
 * may ask for an expiry past 2038, beyond a 32-bit integer, so "expires" is a
 * big one: MySQL and MariaDB refuse such a time for a normal integer column.
 */
final class LwsSubscriptionStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The schema of the entity's tables.
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE) {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $schema['lws_subscription']['indexes']['lws_subscription__storage_active'] = ['storage', 'active'];
    $schema['lws_subscription']['indexes']['lws_subscription__storage_agent'] = ['storage', ['agent', 191]];
    $schema['lws_subscription']['indexes']['lws_subscription__expires'] = ['expires'];
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
    if ($storage_definition->getName() === 'expires') {
      $schema['fields']['expires']['size'] = 'big';
    }
    return $schema;
  }

}
