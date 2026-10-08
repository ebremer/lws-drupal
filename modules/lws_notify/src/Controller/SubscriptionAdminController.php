<?php

declare(strict_types=1);

namespace Drupal\lws_notify\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\lws_notify\Entity\LwsSubscriptionInterface;
use Drupal\lws_notify\Subscriptions;
use Drupal\lws_storage\Entity\LwsStorageInterface;

/**
 * The subscriptions to a storage, for its administrators and owner.
 */
final class SubscriptionAdminController implements ContainerInjectionInterface {

  use AutowireTrait;
  use StringTranslationTrait;

  public function __construct(
    private readonly Subscriptions $subscriptions,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TimeInterface $time,
  ) {}

  /**
   * The page title.
   */
  public function title(LwsStorageInterface $lws_storage): TranslatableMarkup {
    return $this->t('Subscriptions to @storage', ['@storage' => (string) $lws_storage->label()]);
  }

  /**
   * The page: every subscription, live or ended, newest first.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function page(LwsStorageInterface $lws_storage): array {
    $rows = [];
    foreach ($this->subscriptions->forStorage((int) $lws_storage->id()) as $subscription) {
      $expires = $subscription->getExpires();
      $rows[] = [
        $subscription->getAgent() . ($subscription->getClient() !== NULL ? "\n" . $this->t('with @client', ['@client' => $subscription->getClient()]) : ''),
        ['data' => ['#theme' => 'item_list', '#items' => $subscription->getTopics()]],
        $subscription->getInbox(),
        $expires === NULL ? $this->t('Never') : $this->dateFormatter->format($expires, 'short'),
        $this->status($subscription),
        [
          'data' => [
            '#type' => 'operations',
            '#links' => [
              'cancel' => [
                'title' => $this->t('Cancel'),
                'url' => Url::fromRoute('lws_notify.subscription_cancel', [
                  'lws_storage' => $lws_storage->id(),
                  'lws_subscription' => $subscription->id(),
                ]),
              ],
            ],
          ],
        ],
      ];
    }
    return [
      'intro' => [
        '#markup' => '<p>' . $this->t("Agents subscribe through the storage's notification service, and hear of changes to what they may read.") . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Agent'),
          $this->t('Topics'),
          $this->t('Inbox'),
          $this->t('Expires'),
          $this->t('Status'),
          $this->t('Operations'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('Nobody has subscribed to this storage.'),
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * How a subscription is doing.
   */
  private function status(LwsSubscriptionInterface $subscription): TranslatableMarkup {
    $expires = $subscription->getExpires();
    if ($expires !== NULL && $expires <= $this->time->getCurrentTime()) {
      return $this->t('Expired');
    }
    $status = (int) $subscription->get('last_status')->value;
    $answer = $status === 0 ? $this->t('no answer') : (string) $status;
    if (!$subscription->isActive()) {
      return $this->t('Deactivated: its inbox last gave @answer', ['@answer' => $answer]);
    }
    if ($subscription->getFailures() > 0) {
      return $this->t('Active; the last @count deliveries failed (@answer)', [
        '@count' => $subscription->getFailures(),
        '@answer' => $answer,
      ]);
    }
    return $this->t('Active');
  }

}
