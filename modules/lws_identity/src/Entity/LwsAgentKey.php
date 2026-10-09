<?php

declare(strict_types=1);

namespace Drupal\lws_identity\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lws_identity\AgentKeys;
use Drupal\user\EntityOwnerTrait;

/**
 * A public key an agent authenticates with.
 *
 * Create keys with AgentKeys::add(), which checks them.
 */
#[ContentEntityType(
  id: 'lws_agent_key',
  label: new TranslatableMarkup('LWS agent key'),
  label_collection: new TranslatableMarkup('LWS agent keys'),
  label_singular: new TranslatableMarkup('LWS agent key'),
  label_plural: new TranslatableMarkup('LWS agent keys'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'label',
    'owner' => 'uid',
  ],
  handlers: [
    'storage_schema' => LwsAgentKeyStorageSchema::class,
  ],
  admin_permission: 'administer lws agents',
  base_table: 'lws_agent_key',
  label_count: [
    'singular' => '@count LWS agent key',
    'plural' => '@count LWS agent keys',
  ],
)]
class LwsAgentKey extends ContentEntityBase implements LwsAgentKeyInterface {

  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 128);

    $fields['kid'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Key ID'))
      ->setRequired(TRUE)
      ->setSetting('max_length', AgentKeys::MAX_KID_LENGTH)
      ->setSetting('is_ascii', TRUE)
      ->setSetting('case_sensitive', TRUE);

    $fields['jwk'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Public key'))
      ->setDescription(new TranslatableMarkup('The public JWK, as JSON.'))
      ->setRequired(TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Added'));

    // A big integer rather than a core timestamp, which is 32 bits on MySQL.
    $fields['expires'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Expires'))
      ->setSetting('size', 'big');

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getKeyId(): string {
    return (string) $this->get('kid')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getJwk(): array {
    $jwk = json_decode((string) $this->get('jwk')->value, TRUE);
    return is_array($jwk) ? array_map('strval', $jwk) : [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime(): int {
    return (int) $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getExpires(): ?int {
    $value = $this->get('expires')->value;
    return $value === NULL || $value === '' ? NULL : (int) $value;
  }

}
