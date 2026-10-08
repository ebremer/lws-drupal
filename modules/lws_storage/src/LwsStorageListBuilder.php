<?php

declare(strict_types=1);

namespace Drupal\lws_storage;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\Url;
use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The storage list at /admin/content/lws.
 *
 * Administrators see every storage; owners with "manage own lws storages"
 * see theirs.
 */
final class LwsStorageListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  protected $limit = 50;

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    protected LwsUrlGenerator $urls,
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
      $container->get('lws.url_generator'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return array<int|string, int|string>
   *   The IDs of the storages on this page.
   */
  protected function getEntityIds(): array {
    $query = $this->getStorage()->getQuery()
      ->accessCheck(FALSE)
      ->sort('label')
      ->sort('id');
    if ($this->limit) {
      $query->pager($this->limit);
    }
    if (!$this->currentUser->hasPermission('administer lws storages')) {
      $query->condition('owner', (int) $this->currentUser->id());
    }
    return $query->execute();
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The header row.
   */
  public function buildHeader(): array {
    return [
      'label' => $this->t('Storage'),
      'uri' => $this->t('URI'),
      'owner' => ['data' => $this->t('Owner'), 'class' => [RESPONSIVE_PRIORITY_LOW]],
      'used' => $this->t('Used'),
      'status' => $this->t('Status'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The row.
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof LwsStorageInterface);
    $quota = $entity->getQuotaBytes();
    $owner = $entity->get('owner')->entity;
    return [
      'label' => $entity->access('update') ? $entity->toLink(NULL, 'edit-form') : (string) $entity->label(),
      'uri' => $this->urls->storageUri($entity->getSlug()),
      'owner' => $owner === NULL ? '' : (string) $owner->label(),
      'used' => $quota === NULL
        ? ByteSizeMarkup::create($entity->getUsedBytes())
        : $this->t('@used of @quota', [
          '@used' => ByteSizeMarkup::create($entity->getUsedBytes()),
          '@quota' => ByteSizeMarkup::create($quota),
        ]),
      'status' => $entity->isEnabled() ? $this->t('Enabled') : $this->t('Blocked'),
    ] + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, array<string, mixed>>
   *   The operations.
   */
  protected function getDefaultOperations(EntityInterface $entity, ?CacheableMetadata $cacheability = NULL): array {
    $operations = parent::getDefaultOperations($entity, $cacheability);
    $parameters = ['lws_storage' => $entity->id()];
    $access = Url::fromRoute('lws_storage.access', $parameters);
    if ($access->access()) {
      $operations['access'] = ['title' => $this->t('Access'), 'weight' => 20, 'url' => $access];
    }
    $browser = Url::fromRoute('lws_storage.resources', $parameters);
    if ($browser->access()) {
      $operations['resources'] = ['title' => $this->t('Resources'), 'weight' => 30, 'url' => $browser];
    }
    return $operations;
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function render(): array {
    $build = parent::render();
    $build['table']['#empty'] = $this->currentUser->hasPermission('administer lws storages')
      ? $this->t('There are no storages yet.')
      : $this->t('You own no storages.');
    return $build;
  }

}
