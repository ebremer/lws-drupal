<?php

declare(strict_types=1);

namespace Drupal\lws_projection\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lws_projection\Projections;
use Drupal\lws_projection\Projector;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Syncs a projection one step at a time, on cron.
 *
 * Each item is a step: what is left goes back on the queue.
 */
#[QueueWorker(
  id: Projector::QUEUE,
  title: new TranslatableMarkup('LWS projection sync'),
  cron: ['time' => 60],
)]
final class ProjectionSync extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the worker.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\lws_projection\Projector $projector
   *   The projector.
   * @param \Drupal\lws_projection\Projections $projections
   *   The projections.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected Projector $projector,
    protected Projections $projections,
    protected QueueFactory $queueFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container.
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('lws_projection.projector'),
      $container->get('lws_projection.projections'),
      $container->get('queue'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $projection = is_array($data) ? $this->projections->load((string) ($data['projection'] ?? '')) : NULL;
    if ($projection === NULL) {
      return;
    }
    $cursor = is_array($data['cursor'] ?? NULL) ? $data['cursor'] : [];
    $next = $this->projector->syncFrom($projection, $cursor);
    if ($next !== NULL) {
      $this->queueFactory->get(Projector::QUEUE)->createItem(['projection' => $projection->id(), 'cursor' => $next]);
    }
  }

}
