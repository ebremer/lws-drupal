<?php

declare(strict_types=1);

namespace Drupal\lws_index\Drush\Commands;

use Drupal\lws_index\Indexer;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the type index.
 *
 * No command method may be named create(): that is AutowireTrait's factory.
 */
final class LwsIndexCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly Indexer $indexer,
  ) {
    parent::__construct();
  }

  /**
   * Indexes every resource again.
   *
   * The index is kept as resources change; this rebuilds it should it ever
   * be in doubt, such as after resources were changed in the database
   * directly.
   */
  #[CLI\Command(name: 'lws:index:rebuild')]
  public function rebuild(): void {
    $count = $this->indexer->rebuild(function (int $done): void {
      $this->logger()?->info(dt('Indexed @count resources.', ['@count' => $done]));
    });
    $this->logger()?->success(dt('Indexed @count resources.', ['@count' => $count]));
  }

}
