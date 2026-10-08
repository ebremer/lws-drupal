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
  public function isEnabled(): bool {
    return (bool) $this->get('status')->value;
  }

  /**
   * {@inheritdoc}
   *
   * Deletes the storage's resources with it.
   */
  public static function preDelete(EntityStorageInterface $storage, array $entities): void {
    parent::preDelete($storage, $entities);
    $resources = \Drupal::entityTypeManager()->getStorage('lws_resource');
    foreach ($entities as $entity) {
      $ids = $resources->getQuery()->accessCheck(FALSE)->condition('storage', $entity->id())->execute();
      foreach (array_chunk($ids, 100) as $chunk) {
        $resources->delete($resources->loadMultiple($chunk));
      }
    }
  }

}
