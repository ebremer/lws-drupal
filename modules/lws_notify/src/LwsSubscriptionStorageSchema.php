<?php

declare(strict_types=1);

namespace Drupal\lws_notify;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Indexes subscriptions as they are looked up: by storage and by agent.
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

}
