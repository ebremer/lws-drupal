<?php

declare(strict_types=1);

namespace Drupal\lws\Database;

use Drupal\Core\Database\Connection;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;

/**
 * Schema changes the update functions of the LWS modules make.
 */
final class SchemaUpdates {

  /**
   * Makes a base field's column of a content entity type a big integer.
   *
   * For a column whose storage schema handler now asks for one. Core's SQL
   * entity storage does not change a column that holds data itself, so the
   * column is changed here, and so is the installed storage schema, which then
   * matches the handler's and reports no mismatch.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database.
   * @param \Drupal\Core\KeyValueStore\KeyValueStoreInterface $installed
   *   The installed storage schemas: key-value collection
   *   "entity.storage_schema.sql".
   * @param string $entityTypeId
   *   The entity type.
   * @param string $table
   *   Its table that holds the field.
   * @param string $field
   *   The field, whose column has its name.
   *
   * @return bool
   *   FALSE when the column was a big integer already.
   *
   * @throws \RuntimeException
   *   When the field has no such installed column.
   */
  public static function widenToBigInteger(Connection $database, KeyValueStoreInterface $installed, string $entityTypeId, string $table, string $field): bool {
    $key = $entityTypeId . '.field_schema_data.' . $field;
    $schema = $installed->get($key);
    $spec = is_array($schema) ? ($schema[$table]['fields'][$field] ?? NULL) : NULL;
    if (!is_array($spec)) {
      throw new \RuntimeException(sprintf('%s has no installed column %s.%s.', $entityTypeId, $table, $field));
    }
    if (($spec['size'] ?? 'normal') === 'big') {
      return FALSE;
    }
    $spec['size'] = 'big';
    // MySQL, MariaDB and PostgreSQL change the type in place and keep the
    // column's indexes; SQLite's integers are 64 bits whatever the size.
    if ($database->driver() !== 'sqlite') {
      $database->schema()->changeField($table, $field, $field, $spec);
    }
    $schema[$table]['fields'][$field] = $spec;
    $installed->set($key, $schema);
    return TRUE;
  }

}
