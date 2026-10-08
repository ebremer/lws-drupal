<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Hook;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\lws_authz\Policy\PolicyStore;

/**
 * Keeps access policies in step with their storages and their time limits.
 */
final class LwsAuthzHooks {

  public function __construct(
    private readonly PolicyStore $policies,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Implements hook_entity_delete().
   *
   * A deleted storage's policies go with it.
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity): void {
    if ($entity->getEntityTypeId() === 'lws_storage') {
      $this->policies->deleteForStorage((int) $entity->id());
    }
  }

  /**
   * Implements hook_cron().
   *
   * Deletes policies that can no longer permit anything.
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->policies->purgeExpired($this->time->getRequestTime());
  }

}
