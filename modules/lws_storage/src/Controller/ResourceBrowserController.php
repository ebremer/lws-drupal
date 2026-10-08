<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Controller;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\views\Views;

/**
 * The resource browser of a storage: the View lws_resources.
 *
 * Read-only. It calls no LWS endpoint, so it needs no token, and only storage
 * administrators see it (DESIGN.md §5.7). The View is optional configuration;
 * without Views, the page says so.
 */
final class ResourceBrowserController implements ContainerInjectionInterface {

  use AutowireTrait;
  use StringTranslationTrait;

  /**
   * The View, and its display.
   */
  public const VIEW = 'lws_resources';

  public const DISPLAY = 'embed_1';

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly LwsUrlGenerator $urls,
  ) {}

  /**
   * The page title.
   */
  public function title(LwsStorageInterface $lws_storage): TranslatableMarkup {
    return $this->t('Resources of @storage', ['@storage' => (string) $lws_storage->label()]);
  }

  /**
   * The page.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function page(LwsStorageInterface $lws_storage): array {
    $build['uri'] = [
      '#type' => 'item',
      '#title' => $this->t('Storage'),
      '#markup' => $this->urls->storageUri($lws_storage->getSlug()),
    ];
    $view = $this->moduleHandler->moduleExists('views') ? Views::getView(self::VIEW) : NULL;
    if ($view === NULL || !$view->storage->status()) {
      $build['missing'] = [
        '#markup' => '<p>' . $this->t('The resource browser is a View, lws_resources, which needs the Views module.') . '</p>',
      ];
      return $build;
    }
    $build['browser'] = $view->buildRenderable(self::DISPLAY, [(string) $lws_storage->id()]) ?? [];
    return $build;
  }

}
