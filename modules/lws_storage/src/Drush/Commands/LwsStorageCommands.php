<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws_storage\Content\ContentSweeper;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\StorageManager;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for LWS storages.
 *
 * No command method may be named create(): that is AutowireTrait's factory.
 */
final class LwsStorageCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly StorageManager $storages,
    private readonly LwsUrlGenerator $urls,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ContentSweeper $sweeper,
  ) {
    parent::__construct();
  }

  /**
   * Creates a storage and its root container.
   *
   * @param string $slug
   *   The URI segment naming the storage.
   * @param array<string, mixed> $options
   *   The command options.
   */
  #[CLI\Command(name: 'lws:storage:create')]
  #[CLI\Argument(name: 'slug', description: 'The URI segment naming the storage: lower-case letters, digits and hyphens.')]
  #[CLI\Option(name: 'label', description: 'A human-readable name; defaults to the slug.')]
  #[CLI\Option(name: 'controller', description: 'The URI of an agent with full control of the storage. Repeat for several.')]
  #[CLI\Option(name: 'owner', description: 'The ID of the Drupal user who administers the storage.')]
  #[CLI\Option(name: 'authorization-server', description: 'The ID of the authorization server whose tokens it accepts: "local" for this site\'s own, or a trusted server\'s; defaults to the site default (see lws:as:list).')]
  #[CLI\Option(name: 'quota', description: 'The most content the storage may hold, in bytes. No limit by default.')]
  #[CLI\Option(name: 'page-size', description: 'The members on one page of a container listing. The site default (lws_storage.settings:page_size) by default.')]
  #[CLI\Usage(name: 'drush lws:storage:create alice --controller=https://id.example/alice', description: 'Creates the storage /lws/alice/ controlled by that agent.')]
  public function createStorage(
    string $slug,
    array $options = [
      'label' => NULL,
      'controller' => [],
      'owner' => NULL,
      'authorization-server' => NULL,
      'quota' => NULL,
      'page-size' => NULL,
    ],
  ): void {
    $storage = $this->storages->createStorage(
      $slug,
      (string) ($options['label'] ?? $slug),
      array_values(array_map('strval', (array) $options['controller'])),
      $options['owner'] === NULL ? NULL : (int) $options['owner'],
      $options['authorization-server'] === NULL ? NULL : (string) $options['authorization-server'],
      $options['quota'] === NULL ? NULL : (int) $options['quota'],
      $options['page-size'] === NULL ? NULL : (int) $options['page-size'],
    );
    $this->logger()?->success(dt('Created storage @slug at @uri', [
      '@slug' => $storage->getSlug(),
      '@uri' => $this->urls->storageUri($storage->getSlug()),
    ]));
  }

  /**
   * Lists storages.
   *
   * @param array<string, mixed> $options
   *   The command options.
   */
  #[CLI\Command(name: 'lws:storage:list')]
  #[CLI\FieldLabels(labels: [
    'slug' => 'Slug',
    'label' => 'Label',
    'uri' => 'Storage URI',
    'controllers' => 'Controllers',
    'authorization_server' => 'Authorization server',
    'used' => 'Used',
    'quota' => 'Quota',
    'status' => 'Status',
  ])]
  #[CLI\DefaultTableFields(fields: ['slug', 'uri', 'controllers', 'authorization_server', 'status'])]
  public function listStorages(array $options = ['format' => 'table']): RowsOfFields {
    $rows = [];
    foreach ($this->entityTypeManager->getStorage('lws_storage')->loadMultiple() as $storage) {
      if ($storage instanceof LwsStorageInterface) {
        $rows[$storage->getSlug()] = [
          'slug' => $storage->getSlug(),
          'label' => $storage->label(),
          'uri' => $this->urls->storageUri($storage->getSlug()),
          'controllers' => implode(', ', $storage->getControllers()),
          'authorization_server' => $storage->getAuthorizationServerId() ?? '(default)',
          'used' => $storage->getUsedBytes(),
          'quota' => $storage->getQuotaBytes() ?? '',
          'status' => $storage->isEnabled() ? 'enabled' : 'blocked',
        ];
      }
    }
    ksort($rows);
    return new RowsOfFields($rows);
  }

  /**
   * Deletes LWS content that nothing refers to any more.
   *
   * Unused file entities of former versions and deleted resources, and bytes
   * left without a file entity by a process that died while writing. Bytes
   * younger than an hour are kept: they may belong to a write in progress.
   */
  #[CLI\Command(name: 'lws:gc')]
  #[CLI\Usage(name: 'drush lws:gc', description: 'Sweeps unreferenced content. Files queued by deletes are deleted on cron, or with drush queue:run lws_storage_gc.')]
  public function collectGarbage(): void {
    $swept = $this->sweeper->sweep();
    $this->logger()?->success(dt('Deleted @files unused file entities and @bytes stray files.', [
      '@files' => $swept['files'],
      '@bytes' => $swept['bytes'],
    ]));
  }

  /**
   * Deletes a storage and everything in it.
   */
  #[CLI\Command(name: 'lws:storage:delete')]
  #[CLI\Argument(name: 'slug', description: 'The slug of the storage to delete.')]
  public function deleteStorage(string $slug): void {
    $storages = $this->entityTypeManager->getStorage('lws_storage')->loadByProperties(['slug' => $slug]);
    $storage = reset($storages);
    if (!$storage instanceof LwsStorageInterface) {
      throw new \InvalidArgumentException(dt('There is no storage @slug.', ['@slug' => $slug]));
    }
    if (!$this->io()->confirm(dt('Delete the storage @slug and every resource in it?', ['@slug' => $slug]))) {
      return;
    }
    $storage->delete();
    $this->logger()?->success(dt('Deleted storage @slug.', ['@slug' => $slug]));
  }

}
