<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\lws_authz\AccessService\AccessRecords;
use Drupal\lws_authz\Entity\LwsAccessRecordInterface;
use Drupal\lws_authz\Entity\LwsPolicyInterface;
use Drupal\lws_storage\Controller\StorageAccessController;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\StorageRegistry;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Removes an access policy from a storage, with immediate effect.
 *
 * A policy an access grant made is removed by revoking the grant, with all
 * its policies, so that the grant never claims access it no longer gives.
 */
final class PolicyDeleteForm extends ConfirmFormBase {

  /**
   * The storage.
   */
  protected LwsStorageInterface $storage;

  /**
   * The policy.
   */
  protected LwsPolicyInterface $policy;

  /**
   * The grant that made it, if one did.
   */
  protected ?LwsAccessRecordInterface $grant = NULL;

  public function __construct(
    protected AccessRecords $records,
    protected StorageRegistry $storages,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('lws_authz.access_records'), $container->get('lws_storage.storage_registry'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lws_storage_policy_delete';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    if ($this->grant !== NULL) {
      return $this->t('Revoke the grant @uri, and every policy it made?', [
        '@uri' => $this->records->uri($this->storages->ref($this->storage), $this->grant),
      ]);
    }
    $policy = $this->policy->toAccessPolicy();
    return $this->t('Remove the access of @who to @storage: @actions?', [
      '@who' => StorageAccessController::assignee($policy->assignee),
      '@storage' => (string) $this->storage->label(),
      '@actions' => implode(', ', $policy->actions),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Remove');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('lws_storage.access', ['lws_storage' => $this->storage->id()]);
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\lws_storage\Entity\LwsStorageInterface|null $lws_storage
   *   The storage.
   * @param \Drupal\lws_authz\Entity\LwsPolicyInterface|null $lws_policy
   *   The policy, which must be the storage's.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?LwsStorageInterface $lws_storage = NULL, ?LwsPolicyInterface $lws_policy = NULL): array {
    if ($lws_storage === NULL || $lws_policy === NULL || $lws_policy->getStorageId() !== (int) $lws_storage->id()) {
      throw new NotFoundHttpException();
    }
    $this->storage = $lws_storage;
    $this->policy = $lws_policy;
    $this->grant = $this->records->grantOf($this->storages->ref($lws_storage), $lws_policy->getSource());
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
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->grant !== NULL) {
      $this->records->delete($this->storages->ref($this->storage), $this->grant);
    }
    else {
      $this->policy->delete();
    }
    $this->messenger()->addStatus($this->t('The access was removed.'));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
