<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Form;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\lws_authz\Policy\AccessPolicy;
use Drupal\lws_authz\Policy\AccessPolicyParser;
use Drupal\lws_authz\Policy\InvalidPolicyException;
use Drupal\lws_authz\Policy\PolicyStore;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\ResourceLinks;
use Drupal\lws_storage\StorageRegistry;
use Ebremer\Lws\ResourceType;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shares a storage: adds an access policy to it.
 */
final class ShareForm extends FormBase {

  public function __construct(
    protected AccessPolicyParser $parser,
    protected PolicyStore $policies,
    protected StorageRegistry $storages,
    protected ResourceLinks $links,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('lws_authz.policy_parser'),
      $container->get('lws_authz.policy_store'),
      $container->get('lws_storage.storage_registry'),
      $container->get('lws_storage.resource_links'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lws_storage_share';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\lws_storage\Entity\LwsStorageInterface|null $storage
   *   The storage.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?LwsStorageInterface $storage = NULL): array {
    assert($storage !== NULL);
    $form_state->set('storage', $storage);
    $root = $this->storages->ref($storage)->uri . 'root/';
    $form['share'] = [
      '#type' => 'details',
      '#title' => $this->t('Share'),
      '#open' => TRUE,
    ];
    $form['share']['who'] = [
      '#type' => 'radios',
      '#title' => $this->t('With'),
      '#options' => [
        'agent' => $this->t('An agent'),
        AccessPolicy::AUTHENTICATED => $this->t('Every authenticated agent'),
        AccessPolicy::PUBLIC => $this->t('Everyone, without a token'),
      ],
      '#default_value' => 'agent',
      '#required' => TRUE,
    ];
    $form['share']['agent'] = [
      '#type' => 'url',
      '#title' => $this->t('Agent'),
      '#description' => $this->t('The URI that identifies the agent, the "sub" of its access tokens, such as a WebID or a did:key.'),
      '#maxlength' => 2048,
      '#states' => ['visible' => [':input[name="who"]' => ['value' => 'agent']]],
    ];
    $form['share']['actions'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('May'),
      '#options' => [
        'read' => $this->t('Read'),
        'create' => $this->t('Create resources in containers'),
        'modify' => $this->t('Modify'),
        'delete' => $this->t('Delete'),
      ],
      '#default_value' => ['read'],
      '#required' => TRUE,
    ];
    $form['share']['target_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Resources'),
      '#options' => [
        ResourceType::STORAGE_RESOURCE => $this->t('Containers and data resources'),
        ResourceType::CONTAINER => $this->t('Containers only'),
        ResourceType::DATA_RESOURCE => $this->t('Data resources only'),
      ],
      '#default_value' => ResourceType::STORAGE_RESOURCE,
    ];
    $form['share']['targets'] = [
      '#type' => 'textarea',
      '#title' => $this->t('In'),
      '#description' => $this->t('The URIs of resources, one per line. A container includes everything in it, at any depth.'),
      '#default_value' => $root,
      '#rows' => 3,
      '#required' => TRUE,
    ];
    $form['share']['until'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Until'),
      '#description' => $this->t('Leave empty for no end.'),
    ];
    $form['share']['client'] = [
      '#type' => 'url',
      '#title' => $this->t('Only with the client'),
      '#description' => $this->t('The "client_id" of the only application the agent may use. Leave empty for any.'),
      '#maxlength' => 2048,
    ];
    $form['share']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Share'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * The policy is read as any policy is, so the form can make no policy an
   * access grant could not.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $who = (string) $form_state->getValue('who');
    $assignee = $who === 'agent' ? trim((string) $form_state->getValue('agent')) : $who;
    if ($assignee === '') {
      $form_state->setErrorByName('agent', $this->t('Name the agent.'));
      return;
    }
    $until = $form_state->getValue('until');
    $client = trim((string) $form_state->getValue('client'));
    $document = AccessPolicy::document(
      $assignee,
      array_values(array_filter((array) $form_state->getValue('actions'), 'is_string')),
      (string) $form_state->getValue('target_type'),
      array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $form_state->getValue('targets')) ?: []))),
      $until instanceof DrupalDateTime ? $until->format('Y-m-d\TH:i:sP') : NULL,
      $client === '' ? NULL : $client,
    );
    $storage = $form_state->get('storage');
    assert($storage instanceof LwsStorageInterface);
    try {
      $form_state->set('policy', $this->parser->parse($document, $this->storages->ref($storage)));
    }
    catch (InvalidPolicyException $e) {
      $form_state->setErrorByName('share', $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $storage = $form_state->get('storage');
    $policy = $form_state->get('policy');
    assert($storage instanceof LwsStorageInterface && $policy instanceof AccessPolicy);
    $this->policies->add($this->storages->ref($storage), $policy, 'admin', (int) $this->currentUser()->id());
    $this->messenger()->addStatus($this->t('Shared with @who.', ['@who' => $policy->assignee]));
    $this->logger('lws_storage')->notice('Shared @storage with @who: @actions.', [
      '@storage' => $storage->getSlug(),
      '@who' => $policy->assignee,
      '@actions' => implode(', ', $policy->actions),
    ]);
  }

}
