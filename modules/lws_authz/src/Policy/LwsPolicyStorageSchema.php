<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Policy;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Indexes access policies by storage, which every decision looks them up by.
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

}
