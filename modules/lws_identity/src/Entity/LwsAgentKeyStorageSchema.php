<?php

declare(strict_types=1);

namespace Drupal\lws_identity\Entity;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Makes a key ID unique among one user's keys.
 */
final class LwsAgentKeyStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The schema of the entity's tables.
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE) {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $schema['lws_agent_key']['unique keys']['lws_agent_key__uid_kid'] = ['uid', 'kid'];
    return $schema;
  }

}
