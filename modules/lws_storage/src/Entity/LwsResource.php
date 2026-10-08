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
use Drupal\file\FileInterface;
use Drupal\lws\Http\LwsResponse;
use Drupal\lws_storage\Linkset\UserMetadata;
use Drupal\lws_storage\LwsResourceListBuilder;
use Drupal\lws_storage\LwsResourceStorageSchema;
use Drupal\lws_storage\Views\LwsResourceViewsData;

/**
 * A container or data resource.
 *
 * The path and its hash are derived from the parent when the resource is
 * created, and never change: LWS has no move operation.
 */
#[ContentEntityType(
  id: 'lws_resource',
  label: new TranslatableMarkup('LWS resource'),
  label_collection: new TranslatableMarkup('LWS resources'),
  label_singular: new TranslatableMarkup('LWS resource'),
  label_plural: new TranslatableMarkup('LWS resources'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'name',
  ],
  handlers: [
    'storage_schema' => LwsResourceStorageSchema::class,
    'views_data' => LwsResourceViewsData::class,
    'list_builder' => LwsResourceListBuilder::class,
  ],
  admin_permission: 'administer lws storages',
  base_table: 'lws_resource',
  label_count: [
    'singular' => '@count LWS resource',
    'plural' => '@count LWS resources',
  ],
)]
class LwsResource extends ContentEntityBase implements LwsResourceInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['storage'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Storage'))
      ->setSetting('target_type', 'lws_storage')
      ->setRequired(TRUE);

    $fields['parent'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Parent container'))
      ->setDescription(new TranslatableMarkup('Empty only for the storage root.'))
      ->setSetting('target_type', 'lws_resource');

    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Name'))
      ->setDescription(new TranslatableMarkup('The decoded last path segment; containers end in a slash.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setSetting('case_sensitive', TRUE);

    $fields['path'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Path'))
      ->setDescription(new TranslatableMarkup('The decoded path below the storage URI.'))
      ->setSetting('max_length', self::MAX_PATH_LENGTH)
      ->setSetting('case_sensitive', TRUE);

    // MySQL cannot index all of a 2048-character path; lookups go through
    // its hash, which is unique per storage.
    $fields['path_hash'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Path hash'))
      ->setSetting('max_length', 64)
      ->setSetting('is_ascii', TRUE);

    $fields['kind'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Kind'))
      ->setSetting('max_length', 16)
      ->setSetting('is_ascii', TRUE)
      ->addPropertyConstraints('value', ['AllowedValues' => ['choices' => ['container', 'data']]]);

    // The current version of a data resource's content. Core's file field
    // tracks the file's usage by the resource.
    $fields['content'] = BaseFieldDefinition::create('file')
      ->setLabel(new TranslatableMarkup('Content'))
      ->setDescription(new TranslatableMarkup('The managed file with the current content of a data resource.'))
      ->setSetting('target_type', 'file')
      ->setSetting('file_extensions', '')
      ->setSetting('file_directory', 'lws')
      ->setSetting('uri_scheme', 'private');

    $fields['content_sha256'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Content SHA-256'))
      ->setDescription(new TranslatableMarkup('The SHA-256 digest of the content, base64url-encoded.'))
      ->setSetting('max_length', 43)
      ->setSetting('is_ascii', TRUE)
      ->setSetting('case_sensitive', TRUE);

    $fields['creator'] = BaseFieldDefinition::create('uri')
      ->setLabel(new TranslatableMarkup('Creator'))
      ->setDescription(new TranslatableMarkup('The agent that created the resource. For audit only.'));

    $fields['creator_client'] = BaseFieldDefinition::create('uri')
      ->setLabel(new TranslatableMarkup('Creator client'))
      ->setDescription(new TranslatableMarkup('The client the creating agent used. For audit only.'));

    $fields['types'] = BaseFieldDefinition::create('uri')
      ->setLabel(new TranslatableMarkup('Types'))
      ->setDescription(new TranslatableMarkup('The types clients declared with rel="type" links, other than LWS classes.'))
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED);

    $fields['links'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Links'))
      ->setDescription(new TranslatableMarkup('The other links clients manage in the linkset, as JSON: RFC 9264 targets by relation type.'));

    $fields['meta_changed'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Metadata changed'))
      ->setDescription(new TranslatableMarkup('When the links clients manage last changed.'));

    $fields['version'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Version'))
      ->setSetting('unsigned', TRUE)
      ->setSetting('size', 'big')
      ->setDefaultValue(1);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

  /**
   * {@inheritdoc}
   *
   * Derives the path, its hash and the kind of a new resource.
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    if (!$this->isNew()) {
      return;
    }
    $parent = $this->getParent();
    if ($parent !== NULL && (!$parent->isContainer() || $parent->getLwsStorageId() !== $this->getLwsStorageId())) {
      throw new \LogicException('A resource must be created in a container of its own storage.');
    }
    $name = $this->getName();
    $path = $parent === NULL ? $name : $parent->getPath() . $name;
    $this->set('path', $path);
    $this->set('path_hash', hash('sha256', $path));
    $this->set('kind', str_ends_with($name, '/') ? 'container' : 'data');
  }

  /**
   * {@inheritdoc}
   */
  public function getLwsStorageId(): int {
    return (int) $this->get('storage')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getParent(): ?LwsResourceInterface {
    $parent = $this->get('parent')->entity;
    return $parent instanceof LwsResourceInterface ? $parent : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return (string) $this->get('name')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getPath(): string {
    return (string) $this->get('path')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getSegments(): array {
    return explode('/', rtrim($this->getPath(), '/'));
  }

  /**
   * {@inheritdoc}
   */
  public function isContainer(): bool {
    return str_ends_with($this->getName(), '/');
  }

  /**
   * {@inheritdoc}
   */
  public function isRoot(): bool {
    return $this->get('parent')->isEmpty();
  }

  /**
   * {@inheritdoc}
   */
  public function getVersion(): int {
    return (int) $this->get('version')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getUserMetadata(): UserMetadata {
    $types = array_values(array_map(static fn (array $item): string => (string) $item['value'], $this->get('types')->getValue()));
    $links = json_decode((string) $this->get('links')->value, TRUE);
    return new UserMetadata($types, is_array($links) ? $links : []);
  }

  /**
   * {@inheritdoc}
   */
  public function setUserMetadata(UserMetadata $metadata, int $time): static {
    $this->set('types', $metadata->types);
    $this->set('links', $metadata->links === [] ? NULL : json_encode($metadata->links, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $this->set('meta_changed', $time);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadataChangedTime(): int {
    return (int) ($this->get('meta_changed')->value ?? $this->get('created')->value);
  }

  /**
   * {@inheritdoc}
   */
  public function getContentFile(): ?FileInterface {
    $file = $this->get('content')->entity;
    return $file instanceof FileInterface ? $file : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getMediaType(): ?string {
    return $this->getContentFile()?->getMimeType();
  }

  /**
   * {@inheritdoc}
   */
  public function getSize(): ?int {
    $size = $this->getContentFile()?->getSize();
    return $size === NULL ? NULL : (int) $size;
  }

  /**
   * {@inheritdoc}
   */
  public function getEtag(): string {
    if ($this->isContainer()) {
      return 'c' . $this->getVersion();
    }
    return LwsResponse::etag((string) $this->get('content_sha256')->value, (string) $this->getMediaType());
  }

}
