<?php

declare(strict_types=1);

namespace Drupal\lws_notify\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\lws_notify\Subscriptions;

/**
 * Keeps subscriptions in step with their storages, and tidies ended ones.
 */
final class LwsNotifyHooks {

  public function __construct(
    private readonly Subscriptions $subscriptions,
  ) {}

  /**
   * Implements hook_entity_delete().
   *
   * A deleted storage's subscriptions go with it.
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity): void {
    if ($entity->getEntityTypeId() === 'lws_storage') {
      $this->subscriptions->deleteForStorage((int) $entity->id());
    }
  }

  /**
   * Implements hook_cron().
   *
   * Deletes subscriptions that ended a week ago.
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->subscriptions->purge();
  }

}
