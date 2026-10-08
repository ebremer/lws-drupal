<?php

declare(strict_types=1);

namespace Drupal\lws_storage;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\lws_storage\Entity\LwsResourceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The operations on a resource in the resource browser.
 *
 * Resources have no list of their own: the browser is a View, which shows
 * these operations. Nothing here changes a resource; clients do that.
 */
final class LwsResourceListBuilder extends EntityListBuilder {

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    protected FileUrlGeneratorInterface $fileUrls,
    protected AccountInterface $currentUser,
  ) {
    parent::__construct($entity_type, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): self {
    return new self(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('file_url_generator'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, array<string, mixed>>
   *   The operations.
   */
  protected function getDefaultOperations(EntityInterface $entity, ?CacheableMetadata $cacheability = NULL): array {
    $operations = [];
    $file = $entity instanceof LwsResourceInterface ? $entity->getContentFile() : NULL;
    if ($file === NULL || !$this->currentUser->hasPermission('administer lws storages')) {
      return $operations;
    }
    // Served as an attachment, never as a page of this site: see
    // LwsStorageHooks::fileDownload().
    $operations['download'] = [
      'title' => $this->t('Download'),
      'weight' => 0,
      'url' => $this->fileUrls->generate((string) $file->getFileUri()),
    ];
    $media = Url::fromRoute('lws_storage.resource_media', [
      'lws_storage' => $entity->getLwsStorageId(),
      'lws_resource' => $entity->id(),
    ]);
    if ($media->access()) {
      $operations['media'] = ['title' => $this->t('Create media item'), 'weight' => 10, 'url' => $media];
    }
    return $operations;
  }

}
