<?php

declare(strict_types=1);

namespace Drupal\lws_notify\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lws_notify\LwsSubscriptionStorageSchema;

/**
 * A subscription to changes in a storage (LWS Core §10.3).
 *
 * Its topics are the resource URIs it was made for, and its agent and client
 * those of the token it was made with: each notification is authorized for
 * them when the change happens.
 */
#[ContentEntityType(
  id: 'lws_subscription',
  label: new TranslatableMarkup('LWS subscription'),
  label_collection: new TranslatableMarkup('LWS subscriptions'),
  label_singular: new TranslatableMarkup('LWS subscription'),
  label_plural: new TranslatableMarkup('LWS subscriptions'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
  ],
  handlers: [
    'storage_schema' => LwsSubscriptionStorageSchema::class,
  ],
  admin_permission: 'administer lws',
  base_table: 'lws_subscription',
  label_count: [
    'singular' => '@count LWS subscription',
    'plural' => '@count LWS subscriptions',
  ],
)]
class LwsSubscription extends ContentEntityBase implements LwsSubscriptionInterface {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    // The ID of an lws_storage entity, as for lws_policy.
    $fields['storage'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Storage'))
      ->setRequired(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['type'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Subscription type'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64)
      ->setSetting('is_ascii', TRUE);

    $fields['agent'] = BaseFieldDefinition::create('uri')
      ->setLabel(new TranslatableMarkup('Agent'))
      ->setDescription(new TranslatableMarkup('The agent who subscribed.'))
      ->setRequired(TRUE);

    $fields['client'] = BaseFieldDefinition::create('uri')
      ->setLabel(new TranslatableMarkup('Client'))
      ->setDescription(new TranslatableMarkup('The client the agent subscribed with.'));

    $fields['topic'] = BaseFieldDefinition::create('uri')
      ->setLabel(new TranslatableMarkup('Topics'))
      ->setDescription(new TranslatableMarkup('The resources it is about.'))
      ->setRequired(TRUE)
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED);

    $fields['inbox'] = BaseFieldDefinition::create('uri')
      ->setLabel(new TranslatableMarkup('Inbox'))
      ->setRequired(TRUE);

    $fields['expires'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Expires'));

    $fields['active'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Active'))
      ->setDefaultValue(TRUE);

    $fields['failures'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Failed deliveries in a row'))
      ->setSetting('unsigned', TRUE)
      ->setDefaultValue(0);

    $fields['last_status'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Last answer'))
      ->setDescription(new TranslatableMarkup('The HTTP status the inbox last answered with; 0 when it gave none.'));

    $fields['last_attempt'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Last delivery'));

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    return $fields;
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
  public function getType(): string {
    return (string) $this->get('type')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getAgent(): string {
    return (string) $this->get('agent')->value;
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
  public function getTopics(): array {
    return array_map('strval', array_column($this->get('topic')->getValue(), 'value'));
  }

  /**
   * {@inheritdoc}
   */
  public function getInbox(): string {
    return (string) $this->get('inbox')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getExpires(): ?int {
    $value = $this->get('expires')->value;
    return $value === NULL || $value === '' ? NULL : (int) $value;
  }

  /**
   * {@inheritdoc}
   */
  public function isLive(int $now): bool {
    $expires = $this->getExpires();
    return $this->isActive() && ($expires === NULL || $expires > $now);
  }

  /**
   * {@inheritdoc}
   */
  public function isActive(): bool {
    return (bool) $this->get('active')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getFailures(): int {
    return (int) $this->get('failures')->value;
  }

}
