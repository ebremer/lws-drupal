<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lws\Routing\LwsUrlParser;
use Drupal\lws_storage\LwsStorageStorageSchema;
use Drupal\lws_storage\StorageManager;
use Drupal\views\EntityViewsData;

/**
 * An LWS storage.
 *
 * Its root container is the resource with no parent (DESIGN.md §5.1).
 */
#[ContentEntityType(
  id: 'lws_storage',
  label: new TranslatableMarkup('LWS storage'),
  label_collection: new TranslatableMarkup('LWS storages'),
  label_singular: new TranslatableMarkup('LWS storage'),
  label_plural: new TranslatableMarkup('LWS storages'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'label',
  ],
  handlers: [
    'storage_schema' => LwsStorageStorageSchema::class,
    'views_data' => EntityViewsData::class,
  ],
  admin_permission: 'administer lws storages',
  base_table: 'lws_storage',
  label_count: [
    'singular' => '@count LWS storage',
    'plural' => '@count LWS storages',
  ],
)]
class LwsStorage extends ContentEntityBase implements LwsStorageInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    // A slug as the URL parser accepts it, and not one of the reserved first
    // segments under the prefix.
    $slug = '/^(?!(?:' . implode('|', LwsUrlParser::RESERVED) . ')$)' . substr(LwsUrlParser::SLUG, 2);
    $fields['slug'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Slug'))
      ->setDescription(new TranslatableMarkup('The URI segment that names the storage: lower-case letters, digits and hyphens.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 63)
      ->setSetting('is_ascii', TRUE)
      ->setSetting('case_sensitive', TRUE)
      ->addPropertyConstraints('value', [
        'Regex' => [
          'pattern' => $slug,
          'message' => 'A slug is 1 to 63 lower-case letters, digits and hyphens, starting with a letter or digit, and not agents, groups, oauth or roles.',
        ],
      ])
      ->addConstraint('UniqueField');

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255);

    $fields['controllers'] = BaseFieldDefinition::create('uri')
      ->setLabel(new TranslatableMarkup('Storage controllers'))
      ->setDescription(new TranslatableMarkup('Agents with full control of the storage, by URI.'))
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED);

    $fields['owner'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Owner'))
      ->setDescription(new TranslatableMarkup('The Drupal user who administers the storage.'))
      ->setSetting('target_type', 'user');

    $fields['authorization_server'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Authorization server'))
      ->setDescription(new TranslatableMarkup('The authorization server whose access tokens the storage accepts, by ID: "local" for this site\'s own, or a trusted server\'s. Empty for the site default.'))
      ->setSetting('max_length', 64)
      ->setSetting('is_ascii', TRUE);

    $fields['quota_bytes'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Quota'))
      ->setDescription(new TranslatableMarkup('The most content the storage may hold, in bytes. Empty for no limit.'))
      ->setSetting('unsigned', TRUE)
      ->setSetting('size', 'big');

    // Kept up to date with SQL expressions in the transactions that change
    // content, so that concurrent writes are all counted.
    $fields['used_bytes'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Used'))
      ->setDescription(new TranslatableMarkup('The content the storage holds, in bytes.'))
      ->setSetting('unsigned', TRUE)
      ->setSetting('size', 'big')
      ->setDefaultValue(0);

    $fields['page_size'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Page size'))
      ->setDescription(new TranslatableMarkup('The members on one page of a container listing. Empty for the site default.'))
      ->setSetting('unsigned', TRUE)
      ->setSetting('min', 1);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Enabled'))
      ->setDefaultValue(TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getSlug(): string {
    return (string) $this->get('slug')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getControllers(): array {
    return array_values(array_map(static fn (array $item): string => (string) $item['value'], $this->get('controllers')->getValue()));
  }

  /**
   * {@inheritdoc}
   */
  public function getOwnerId(): ?int {
    $owner = $this->get('owner');
    return $owner->isEmpty() ? NULL : (int) $owner->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getAuthorizationServerId(): ?string {
    $id = $this->get('authorization_server')->value;
    return $id === NULL || $id === '' ? NULL : (string) $id;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuotaBytes(): ?int {
    $quota = $this->get('quota_bytes')->value;
    return $quota === NULL || $quota === '' ? NULL : (int) $quota;
  }

  /**
   * {@inheritdoc}
   */
  public function getUsedBytes(): int {
    return (int) $this->get('used_bytes')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getPageSize(): ?int {
    $size = $this->get('page_size')->value;
    return $size === NULL || $size === '' ? NULL : (int) $size;
  }

  /**
   * {@inheritdoc}
   */
  public function isEnabled(): bool {
    return (bool) $this->get('status')->value;
  }

  /**
   * {@inheritdoc}
   *
   * Deletes the storage's resources with it, and queues their files for
   * deletion.
   */
  public static function preDelete(EntityStorageInterface $storage, array $entities): void {
    parent::preDelete($storage, $entities);
    $resources = \Drupal::entityTypeManager()->getStorage('lws_resource');
    $queue = \Drupal::queue(StorageManager::GC_QUEUE);
    foreach ($entities as $entity) {
      $ids = $resources->getQuery()->accessCheck(FALSE)->condition('storage', $entity->id())->execute();
      foreach (array_chunk($ids, 100) as $chunk) {
        $loaded = $resources->loadMultiple($chunk);
        foreach ($loaded as $resource) {
          $fid = $resource instanceof LwsResourceInterface ? $resource->getContentFile()?->id() : NULL;
          if ($fid !== NULL) {
            $queue->createItem(['fid' => (int) $fid]);
          }
        }
        $resources->delete($loaded);
      }
    }
  }

}
