<?php

declare(strict_types=1);

namespace Drupal\lws\EventSubscriber;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Clears cached routing when the LWS path prefix changes.
 *
 * The router caches the processed path of every URL it has seen, so URLs
 * under an old prefix would keep reaching LWS routes, and URLs under the new
 * one could hit stale entries.
 */
final class LwsConfigSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ConfigEvents::SAVE => 'onSave'];
  }

  /**
   * Invalidates cached routes when lws.settings:prefix changes.
   */
  public function onSave(ConfigCrudEvent $event): void {
    if ($event->getConfig()->getName() === 'lws.settings' && $event->isChanged('prefix')) {
      $this->cacheTagsInvalidator->invalidateTags(['route_match']);
    }
  }

}
