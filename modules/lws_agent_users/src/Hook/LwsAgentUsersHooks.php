<?php

declare(strict_types=1);

namespace Drupal\lws_agent_users\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\externalauth\AuthmapInterface;
use Drupal\lws_agent_users\AgentUsers;
use Drupal\lws_agent_users\Pruner;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * The user fields of agent links, and the guards around them.
 */
final class LwsAgentUsersHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly AuthmapInterface $authmap,
    private readonly Pruner $pruner,
  ) {}

  /**
   * Implements hook_entity_base_field_info().
   *
   * @return array<string, \Drupal\Core\Field\BaseFieldDefinition>
   *   The fields of users.
   */
  #[Hook('entity_base_field_info')]
  public function entityBaseFieldInfo(EntityTypeInterface $entity_type): array {
    if ($entity_type->id() !== 'user') {
      return [];
    }
    $fields[AgentUsers::URI_FIELD] = BaseFieldDefinition::create('uri')
      ->setLabel($this->t('LWS agent URI'))
      ->setDescription($this->t('The LWS agent that acts as this user, with its access tokens, such as a WebID or a DID. A user has at most one, and an agent acts as at most one user.'))
      ->setSetting('max_length', AgentUsers::MAX_URI_BYTES)
      ->addConstraint('LwsAgentUri')
      ->setDisplayOptions('form', ['type' => 'uri', 'weight' => 20]);
    $fields[AgentUsers::PROVISIONED_FIELD] = BaseFieldDefinition::create('boolean')
      ->setLabel($this->t('Made for an LWS agent'))
      ->setDescription($this->t('Made by the first access token of the agent above. It cannot log in, and is deleted once unseen for as long as the LWS agent users settings say.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => 21]);
    return $fields;
  }

  /**
   * Implements hook_entity_field_access().
   *
   * Only user administrators see or change which agent acts as a user.
   *
   * @param string $operation
   *   The operation.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user.
   * @param \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface>|null $items
   *   The field's values.
   */
  #[Hook('entity_field_access')]
  public function entityFieldAccess(string $operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL): AccessResultInterface {
    $fields = [AgentUsers::URI_FIELD, AgentUsers::PROVISIONED_FIELD];
    if ($field_definition->getTargetEntityTypeId() !== 'user' || !in_array($field_definition->getName(), $fields, TRUE)) {
      return AccessResult::neutral();
    }
    return AccessResult::forbiddenIf(!$account->hasPermission('administer users'))->cachePerPermissions();
  }

  /**
   * Implements hook_ENTITY_TYPE_insert() for user.
   */
  #[Hook('user_insert')]
  public function userInsert(EntityInterface $user): void {
    $this->link($user);
  }

  /**
   * Implements hook_ENTITY_TYPE_update() for user.
   */
  #[Hook('user_update')]
  public function userUpdate(EntityInterface $user): void {
    $this->link($user);
  }

  /**
   * Implements hook_form_FORM_ID_alter() for user_login_form.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  #[Hook('form_user_login_form_alter')]
  public function formUserLoginFormAlter(array &$form, FormStateInterface $form_state): void {
    // Before validateFinal(), which refuses what has no uid, as for a wrong
    // password, and counts it against the flood limits.
    $form['#validate'] ??= [];
    $final = array_search('::validateFinal', $form['#validate'], TRUE);
    $refuse = [self::class, 'refuseProvisioned'];
    array_splice($form['#validate'], $final === FALSE ? count($form['#validate']) : (int) $final, 0, [$refuse]);
  }

  /**
   * Refuses to log in an account made for an agent.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function refuseProvisioned(array &$form, FormStateInterface $form_state): void {
    $uid = $form_state->get('uid');
    $user = $uid ? User::load($uid) : NULL;
    if ($user instanceof UserInterface && $user->get(AgentUsers::PROVISIONED_FIELD)->value) {
      $form_state->set('uid', FALSE);
    }
  }

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->pruner->prune();
  }

  /**
   * Keeps the authmap entry of a user as its agent URI field says.
   *
   * Another user with the same agent URI makes the save fail; the field's
   * constraint says so first, in forms and validated saves.
   */
  private function link(EntityInterface $user): void {
    if (!$user instanceof UserInterface || $user->isAnonymous()) {
      return;
    }
    $uri = (string) $user->get(AgentUsers::URI_FIELD)->value;
    if ($uri === '') {
      $this->authmap->delete((int) $user->id(), AgentUsers::PROVIDER);
    }
    elseif ($this->authmap->get((int) $user->id(), AgentUsers::PROVIDER) !== AgentUsers::authname($uri)) {
      $this->authmap->save($user, AgentUsers::PROVIDER, AgentUsers::authname($uri));
    }
  }

}
