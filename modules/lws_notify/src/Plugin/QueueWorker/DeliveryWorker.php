<?php

declare(strict_types=1);

namespace Drupal\lws_notify\Plugin\QueueWorker;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lws_notify\Delivery\Deliverer;
use Drupal\lws_notify\Delivery\Delivery;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Delivers queued notifications: retries, and what a request left.
 *
 * An item whose time has not come goes back to the queue until it has.
 */
#[QueueWorker(
  id: Deliverer::QUEUE,
  title: new TranslatableMarkup('LWS notification deliveries'),
  cron: ['time' => 30],
)]
final class DeliveryWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the worker.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\lws_notify\Delivery\Deliverer $deliverer
   *   The deliverer.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected Deliverer $deliverer,
    protected TimeInterface $time,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self($configuration, $plugin_id, $plugin_definition, $container->get('lws_notify.deliverer'), $container->get('datetime.time'));
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $wait = is_array($data) ? (int) ($data['not_before'] ?? 0) - $this->time->getCurrentTime() : 0;
    if ($wait > 0) {
      throw new DelayedRequeueException($wait);
    }
    $delivery = Delivery::fromItem($data);
    if ($delivery !== NULL) {
      $this->deliverer->deliverQueued($delivery);
    }
  }

}
