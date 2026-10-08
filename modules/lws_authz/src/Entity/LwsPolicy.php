<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lws_authz\Policy\AccessPolicy;
use Drupal\lws_authz\Policy\Constraint;
use Drupal\lws_authz\Policy\LwsPolicyStorageSchema;
use Drupal\user\EntityOwnerTrait;
use Drupal\views\EntityViewsData;

/**
 * A stored access policy, one per AccessPolicy object (DESIGN.md D5).
 *
 * Storage controllers need none: they may do anything in their storage.
 */
#[ContentEntityType(
  id: 'lws_policy',
  label: new TranslatableMarkup('LWS access policy'),
  label_collection: new TranslatableMarkup('LWS access policies'),
  label_singular: new TranslatableMarkup('LWS access policy'),
  label_plural: new TranslatableMarkup('LWS access policies'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'owner' => 'uid',
  ],
  handlers: [
    'storage_schema' => LwsPolicyStorageSchema::class,
    'views_data' => EntityViewsData::class,
  ],
  admin_permission: 'administer lws',
  base_table: 'lws_policy',
  label_count: [
    'singular' => '@count LWS access policy',
    'plural' => '@count LWS access policies',
  ],
)]
class LwsPolicy extends ContentEntityBase implements LwsPolicyInterface {

  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    // The ID of an lws_storage entity, not a reference: this module does not
    // depend on lws_storage.
    $fields['storage'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Storage'))
      ->setRequired(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['source'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Source'))
      ->setDescription(new TranslatableMarkup('"admin", or "grant:{id}" for a policy an access grant made.'))
      ->setRequired(TRUE)
      ->setDefaultValue('admin')
      ->setSetting('max_length', 255)
      ->setSetting('is_ascii', TRUE);

    $fields['actions'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Actions'))
      ->setRequired(TRUE)
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setSetting('max_length', 16)
      ->setSetting('is_ascii', TRUE)
      ->addPropertyConstraints('value', ['AllowedValues' => ['choices' => AccessPolicy::ACTIONS]]);

    $fields['assignee'] = BaseFieldDefinition::create('uri')
      ->setLabel(new TranslatableMarkup('Assignee'))
      ->setDescription(new TranslatableMarkup('The agent it is for; foaf:Agent for everyone, acl:AuthenticatedAgent for every authenticated agent.'))
      ->setRequired(TRUE);

    $fields['target_type'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Target type'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64)
      ->setSetting('is_ascii', TRUE)
      ->addPropertyConstraints('value', ['AllowedValues' => ['choices' => AccessPolicy::TARGET_TYPES]]);

    $fields['target_values'] = BaseFieldDefinition::create('uri')
      ->setLabel(new TranslatableMarkup('Targets'))
      ->setDescription(new TranslatableMarkup('The resources it applies to, and the containers whose resources it applies to.'))
      ->setRequired(TRUE)
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED);

    $fields['constraints'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Constraints'))
      ->setDescription(new TranslatableMarkup('A JSON list of constraint objects.'))
      ->setDefaultValue('[]');

    $fields['not_after'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Not after'))
      ->setDescription(new TranslatableMarkup('When its dateTime constraints end it, for purging; evaluation does not rely on it.'));

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
  public function getSource(): string {
    return (string) $this->get('source')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function toAccessPolicy(): AccessPolicy {
    $constraints = [];
    $json = json_decode((string) $this->get('constraints')->value, TRUE);
    foreach (is_array($json) ? $json : [] as $constraint) {
      if (is_array($constraint) && is_string($constraint['leftOperand'] ?? NULL) && is_string($constraint['operator'] ?? NULL)) {
        $right = $constraint['rightOperand'] ?? NULL;
        $right = match (TRUE) {
          is_string($right) => $right,
          is_array($right) => array_values(array_filter($right, 'is_string')),
          // A constraint that cannot be read cannot hold.
          default => '',
        };
        $constraints[] = new Constraint($constraint['leftOperand'], $constraint['operator'], $right);
      }
      else {
        $constraints[] = new Constraint('', '', '');
      }
    }
    return new AccessPolicy(
      self::values($this, 'actions'),
      (string) $this->get('assignee')->value,
      (string) $this->get('target_type')->value,
      self::values($this, 'target_values'),
      $constraints,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function setAccessPolicy(AccessPolicy $policy): static {
    $this->set('actions', $policy->actions);
    $this->set('assignee', $policy->assignee);
    $this->set('target_type', $policy->targetType);
    $this->set('target_values', $policy->targetValues);
    $constraints = array_map(static fn (Constraint $constraint): array => $constraint->toJson(), $policy->constraints);
    $this->set('constraints', json_encode($constraints, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $this->set('not_after', $policy->notAfter());
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getNotAfter(): ?int {
    $value = $this->get('not_after')->value;
    return $value === NULL ? NULL : (int) $value;
  }

  /**
   * {@inheritdoc}
   *
   * The policy is derived again, so that not_after cannot disagree with it.
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    $this->set('not_after', $this->toAccessPolicy()->notAfter());
  }

  /**
   * The values of a multi-value string field.
   *
   * @return list<string>
   *   The values.
   */
  private static function values(LwsPolicy $policy, string $field): array {
    return array_map('strval', array_column($policy->get($field)->getValue(), 'value'));
  }

}
