<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lws_authz\AccessService\LwsAccessRecordStorageSchema;
use Drupal\user\EntityOwnerTrait;
use Drupal\views\EntityViewsData;

/**
 * An access request or access grant (LWS Core §11).
 *
 * The document is kept as it was submitted, with its "id" added. A grant's
 * policies are lws_policy entities whose source is "grant:{uuid}".
 */
#[ContentEntityType(
  id: 'lws_access',
  label: new TranslatableMarkup('LWS access request or grant'),
  label_collection: new TranslatableMarkup('LWS access requests and grants'),
  label_singular: new TranslatableMarkup('LWS access request or grant'),
  label_plural: new TranslatableMarkup('LWS access requests and grants'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'owner' => 'uid',
  ],
  handlers: [
    'storage_schema' => LwsAccessRecordStorageSchema::class,
    'views_data' => EntityViewsData::class,
  ],
  admin_permission: 'administer lws',
  base_table: 'lws_access',
  label_count: [
    'singular' => '@count LWS access request or grant',
    'plural' => '@count LWS access requests and grants',
  ],
)]
class LwsAccessRecord extends ContentEntityBase implements LwsAccessRecordInterface {

  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['kind'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Kind'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 16)
      ->setSetting('is_ascii', TRUE)
      ->addPropertyConstraints('value', ['AllowedValues' => ['choices' => [self::REQUEST, self::GRANT]]]);

    // The ID of an lws_storage entity, as for lws_policy.
    $fields['storage'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Storage'))
      ->setRequired(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['creator'] = BaseFieldDefinition::create('uri')
      ->setLabel(new TranslatableMarkup('Submitted by'))
      ->setDescription(new TranslatableMarkup('The agent who submitted it over LWS.'));

    $fields['client'] = BaseFieldDefinition::create('uri')
      ->setLabel(new TranslatableMarkup('Client'))
      ->setDescription(new TranslatableMarkup('The client the agent submitted it with.'));

    $fields['assignees'] = BaseFieldDefinition::create('uri')
      ->setLabel(new TranslatableMarkup('Assignees'))
      ->setDescription(new TranslatableMarkup('The assignees of its policies, who may see it.'))
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED);

    $fields['inbox'] = BaseFieldDefinition::create('uri')
      ->setLabel(new TranslatableMarkup('Inbox'));

    $fields['document'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Document'))
      ->setRequired(TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getKind(): string {
    return (string) $this->get('kind')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getStorageId(): int {
    return (int) $this->get('storage')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getCreator(): ?string {
    $value = $this->get('creator')->value;
    return is_string($value) && $value !== '' ? $value : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getClient(): ?string {
    $value = $this->get('client')->value;
    return is_string($value) && $value !== '' ? $value : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getInbox(): ?string {
    $value = $this->get('inbox')->value;
    return is_string($value) && $value !== '' ? $value : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getDocument(): array {
    $document = json_decode((string) $this->get('document')->value, TRUE);
    return is_array($document) ? $document : [];
  }

}
