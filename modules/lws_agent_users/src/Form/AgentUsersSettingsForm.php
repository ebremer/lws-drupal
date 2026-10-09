<?php

declare(strict_types=1);

namespace Drupal\lws_agent_users\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\lws_agent_users\AgentUsers;
use Drupal\user\RoleInterface;

/**
 * Settings of agent users: which agents get accounts, and for how long.
 */
final class AgentUsersSettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AgentUsers $agentUsers,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lws_agent_users_settings';
  }

  /**
   * {@inheritdoc}
   *
   * @return list<string>
   *   The configuration names.
   */
  protected function getEditableConfigNames(): array {
    return ['lws_agent_users.settings'];
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('An agent that acts as a Drupal user is that user in its LWS requests, and only there. Access policies can name the user&rsquo;s roles as assignees, by URIs such as <code>@uri</code>; blocking the user bars the agent; and the <em>Bypass LWS access policies</em> permission makes it a controller of every storage. Link an agent to a user on the user&rsquo;s edit form. With LWS Identity, every user&rsquo;s own agent acts as that user.', [
        '@uri' => $this->agentUsers->roleUri('editor'),
      ]) . '</p>',
    ];
    $form['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Agents without an account'),
      '#options' => [
        'link_only' => $this->t('Stay agents only'),
        'provision' => $this->t('Get an account with their first access token'),
      ],
      '#description' => $this->t('An account made for an agent has no password or e-mail address and cannot log in. Every agent ever seen gets one, and with pseudonymous identifiers each pseudonym does: limit them below.'),
      '#config_target' => 'lws_agent_users.settings:mode',
    ];
    $provisioning = ['visible' => [':input[name="mode"]' => ['value' => 'provision']]];
    $form['issuers'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Only for tokens from these authorization servers'),
      '#description' => $this->t('Issuer identifiers, one per line, such as this site&rsquo;s own; none, any a storage trusts.'),
      '#rows' => 3,
      '#states' => $provisioning,
      '#config_target' => self::lines('provision.issuers'),
    ];
    $form['prefixes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Only for agent URIs that start with'),
      '#description' => $this->t('One per line, such as <code>https://idp.example/realms/lws/</code>; none, any.'),
      '#rows' => 3,
      '#states' => $provisioning,
      '#config_target' => self::lines('provision.prefixes'),
    ];
    $form['roles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Roles of new accounts'),
      '#options' => $this->roleOptions(),
      '#description' => $this->t('Besides <em>Authenticated user</em>. Administrator roles cannot be given.'),
      '#states' => $provisioning,
      '#config_target' => new ConfigTarget(
        'lws_agent_users.settings',
        'provision.roles',
        fromConfig: static fn (?array $roles): array => array_combine($roles ?? [], $roles ?? []),
        toConfig: static fn (?array $checked): array => array_values(array_filter(array_map('strval', $checked ?? []))),
      ),
    ];
    $form['per_hour'] = [
      '#type' => 'number',
      '#title' => $this->t('Accounts made per hour, at most'),
      '#min' => 0,
      '#states' => $provisioning,
      '#config_target' => 'lws_agent_users.settings:provision.per_hour',
    ];
    $form['prune_after_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Delete accounts made for agents after this many days unseen'),
      '#description' => $this->t('Their content then belongs to Anonymous. 0 keeps them.'),
      '#min' => 0,
      '#field_suffix' => $this->t('days'),
      '#config_target' => 'lws_agent_users.settings:prune_after_days',
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    foreach (self::split((string) $form_state->getValue('issuers')) as $issuer) {
      $scheme = strtolower((string) parse_url($issuer, PHP_URL_SCHEME));
      if (!in_array($scheme, ['https', 'http'], TRUE) || parse_url($issuer, PHP_URL_HOST) === NULL) {
        $form_state->setErrorByName('issuers', $this->t('%issuer is not an issuer identifier: an HTTPS URL.', ['%issuer' => $issuer]));
      }
    }
    foreach (self::split((string) $form_state->getValue('prefixes')) as $prefix) {
      if (preg_match('/^[A-Za-z][A-Za-z0-9+.\-]*:[\x21-\x7E]*$/', $prefix) !== 1) {
        $form_state->setErrorByName('prefixes', $this->t('%prefix is not the start of an absolute URI.', ['%prefix' => $prefix]));
      }
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * The roles new accounts may get.
   *
   * @return array<string, string>
   *   Labels by role ID.
   */
  private function roleOptions(): array {
    $options = [];
    foreach ($this->entityTypeManager->getStorage('user_role')->loadMultiple() as $id => $role) {
      if (!$role->isAdmin() && !in_array($id, [RoleInterface::ANONYMOUS_ID, RoleInterface::AUTHENTICATED_ID], TRUE)) {
        $options[(string) $id] = (string) $role->label();
      }
    }
    return $options;
  }

  /**
   * The target of a list kept one item per line.
   */
  private static function lines(string $key): ConfigTarget {
    return new ConfigTarget(
      'lws_agent_users.settings',
      $key,
      fromConfig: static fn (?array $values): string => implode("\n", $values ?? []),
      toConfig: static fn (?string $text): array => self::split((string) $text),
    );
  }

  /**
   * The non-empty lines of a text, trimmed, without repeats.
   *
   * @return list<string>
   *   The lines.
   */
  private static function split(string $text): array {
    return array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', $text) ?: []), static fn (string $line): bool => $line !== '')));
  }

}
