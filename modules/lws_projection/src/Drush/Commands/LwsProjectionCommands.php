<?php

declare(strict_types=1);

namespace Drupal\lws_projection\Drush\Commands;

use Drupal\lws_projection\Projections;
use Drupal\lws_projection\Projector;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for projections of Drupal content.
 *
 * No command method may be named create(): that is AutowireTrait's factory.
 */
final class LwsProjectionCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly Projector $projector,
    private readonly Projections $projections,
  ) {
    parent::__construct();
  }

  /**
   * Syncs projections now, rather than on cron.
   *
   * @param string|null $projection
   *   The projection's machine name; all of them if none.
   */
  #[CLI\Command(name: 'lws:projection:sync')]
  #[CLI\Argument(name: 'projection', description: 'The machine name of a projection; all of them if none is given.')]
  #[CLI\Usage(name: 'drush lws:projection:sync content', description: 'Projects all the content of the projection "content", and takes out what no longer belongs.')]
  public function sync(?string $projection = NULL): void {
    $projections = $projection === NULL ? $this->projections->all() : array_filter([$this->projections->load($projection)]);
    if ($projections === []) {
      throw new \InvalidArgumentException(dt('There is no projection @name.', ['@name' => (string) $projection]));
    }
    foreach ($projections as $item) {
      $this->projector->sync($item);
      $this->logger()?->success(dt('Synced @label at /@slug/.', [
        '@label' => (string) $item->label(),
        '@slug' => $item->getSlug(),
      ]));
    }
  }

}
