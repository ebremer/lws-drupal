<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws_authz\AuthorizationServers;
use Drupal\lws_authz\Server\LocalAuthorizationServer;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\Listing\ContainerPager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds or edits a storage.
 *
 * Saving a new storage makes its root container. The slug cannot change once
 * the storage exists, as every URI in it, and every token for it, has it.
 */
final class LwsStorageForm extends ContentEntityForm {

  /**
   * The authorization servers.
   */
  protected AuthorizationServers $servers;

  /**
   * The LWS URL generator.
   */
  protected LwsUrlGenerator $urls;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $form = parent::create($container);
    $form->servers = $container->get('lws_authz.authorization_servers');
    $form->urls = $container->get('lws.url_generator');
    return $form;
  }

  /**
   * The storage being edited.
   */
  private function storage(): LwsStorageInterface {
    $storage = $this->getEntity();
    assert($storage instanceof LwsStorageInterface);
    return $storage;
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
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $storage = $this->storage();
    if (!$storage->isNew()) {
      $form['slug']['#disabled'] = TRUE;
      $form['uri'] = [
        '#type' => 'item',
        '#title' => $this->t('URI'),
        '#markup' => $this->urls->storageUri($storage->getSlug()),
        '#description' => $this->t('The slug is part of every URI in the storage, and of every access token for it, so it cannot change.'),
        '#weight' => -9,
      ];
    }

    $servers = ['' => $this->t('The site default (@server)', ['@server' => $this->serverLabel($this->servers->defaultId())])];
    $servers[LocalAuthorizationServer::ID] = $this->serverLabel(LocalAuthorizationServer::ID);
    foreach ($this->entityTypeManager->getStorage('lws_trusted_as')->loadMultiple() as $id => $server) {
      $servers[$id] = (string) $server->label();
    }
    $form['authorization_server'] = [
      '#type' => 'select',
      '#title' => $this->t('Authorization server'),
      '#description' => $this->t('The server whose access tokens the storage accepts. Tokens from any other are refused.'),
      '#options' => $servers,
      '#default_value' => $storage->getAuthorizationServerId() ?? '',
      '#weight' => 10,
    ];

    $quota = $storage->getQuotaBytes();
    $form['quota'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Quota'),
      '#description' => $this->t('The most content the storage may hold, such as <em>10 GB</em>. Writes beyond it answer 507 Insufficient Storage. Empty for no limit. It holds @used now.', [
        '@used' => ByteSizeMarkup::create($storage->getUsedBytes()),
      ]),
      '#default_value' => $quota === NULL ? '' : ByteSize::format($quota),
      '#size' => 12,
      '#weight' => 20,
    ];
    $form['page_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Page size'),
      '#description' => $this->t('The members on one page of a container listing. Empty for the site default.'),
      '#min' => 1,
      '#max' => ContainerPager::MAX_PAGE_SIZE,
      '#default_value' => $storage->getPageSize(),
      '#weight' => 30,
    ];
    $required = $storage->requiresIfMatch();
    $form['require_if_match'] = [
      '#type' => 'select',
      '#title' => $this->t('Conditional changes'),
      '#description' => $this->t('Whether replacing, patching and deleting need If-Match, so that clients cannot overwrite changes they have not seen. Without it they answer 428 Precondition Required.'),
      '#options' => [
        '' => $this->t('The site default'),
        '1' => $this->t('Require If-Match'),
        '0' => $this->t('Do not require If-Match'),
      ],
      '#default_value' => $required === NULL ? '' : ($required ? '1' : '0'),
      '#weight' => 40,
    ];
    return $form;
  }

  /**
   * How an authorization server reads.
   */
  private function serverLabel(string $id): string {
    if ($id === LocalAuthorizationServer::ID) {
      return (string) $this->t('This site');
    }
    $server = $this->entityTypeManager->getStorage('lws_trusted_as')->load($id);
    return $server === NULL ? $id : (string) $server->label();
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The storage, as the form would save it.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): EntityInterface {
    $quota = trim((string) $form_state->getValue('quota'));
    if ($quota !== '' && ByteSize::parse($quota) === NULL) {
      $form_state->setErrorByName('quota', $this->t('Give the quota as a size, such as 500 MB or 10 GB.'));
    }
    return parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The storage.
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
    parent::copyFormValuesToEntity($entity, $form, $form_state);
    assert($entity instanceof LwsStorageInterface);
    $server = (string) $form_state->getValue('authorization_server');
    $quota = (string) $form_state->getValue('quota');
    $pageSize = $form_state->getValue('page_size');
    $required = (string) $form_state->getValue('require_if_match');
    $entity->set('authorization_server', $server === '' ? NULL : $server);
    $entity->set('quota_bytes', ByteSize::parse($quota));
    $entity->set('page_size', $pageSize === NULL || $pageSize === '' ? NULL : (int) $pageSize);
    $entity->set('require_if_match', $required === '' ? NULL : $required === '1');
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $storage = $this->storage();
    $status = $storage->save();
    $arguments = ['%label' => (string) $storage->label(), '@uri' => $this->urls->storageUri($storage->getSlug())];
    $this->messenger()->addStatus($status === SAVED_NEW
      ? $this->t('Created the storage %label at @uri.', $arguments)
      : $this->t('Saved the storage %label.', $arguments));
    $this->logger('lws')->notice('@action storage %label (@uri).', $arguments + ['@action' => $status === SAVED_NEW ? 'Created' : 'Changed']);
    $form_state->setRedirectUrl($storage->toUrl('collection'));
    return $status;
  }

}
