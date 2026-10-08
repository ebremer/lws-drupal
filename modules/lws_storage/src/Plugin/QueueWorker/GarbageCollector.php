<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lws_storage\StorageManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Deletes the files of deleted resources, once nothing uses them.
 *
 * Recursive deletes queue their files here rather than deleting them in the
 * request. A file that something else still uses, such as a Media item made
 * from it, is kept.
 */
#[QueueWorker(
  id: StorageManager::GC_QUEUE,
  title: new TranslatableMarkup('LWS content clean-up'),
  cron: ['time' => 30],
)]
final class GarbageCollector extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The storage manager, which knows when a file may go.
   */
  protected StorageManager $manager;

  /**
   * Constructs the worker.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\lws_storage\StorageManager $manager
   *   The storage manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, StorageManager $manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->manager = $manager;
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
    return new self($configuration, $plugin_id, $plugin_definition, $container->get('lws_storage.storage_manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (is_array($data) && isset($data['fid'])) {
      $this->manager->releaseFile((int) $data['fid']);
    }
  }

}
