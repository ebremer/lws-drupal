<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\lws_authz\AccessService\AccessRecords;
use Drupal\lws_authz\Entity\LwsAccessRecordInterface;
use Drupal\lws_authz\Policy\InvalidPolicyException;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\StorageRegistry;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Approves an access request, which grants it, or denies it.
 *
 * Either way the request is settled and goes; an approved request leaves a
 * grant that names its inbox.
 */
final class AccessRequestForm extends ConfirmFormBase {

  /**
   * The storage.
   */
  protected LwsStorageInterface $storage;

  /**
   * The request.
   */
  protected LwsAccessRecordInterface $request;

  /**
   * Whether the request is approved, else denied.
   */
  protected bool $approve;

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
    return 'lws_storage_access_request';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    $arguments = ['@agent' => (string) $this->request->getCreator(), '@storage' => (string) $this->storage->label()];
    return $this->approve
      ? $this->t('Grant @agent what it asks of @storage?', $arguments)
      : $this->t('Deny the request of @agent to @storage?', $arguments);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->approve
      ? $this->t('It takes effect at once, until the grant is revoked.')
      : $this->t('The request is deleted.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->approve ? $this->t('Approve') : $this->t('Deny');
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
   * @param \Drupal\lws_authz\Entity\LwsAccessRecordInterface|null $lws_access
   *   The request, which must be the storage's.
   * @param string $decision
   *   The decision: "approve" or "deny".
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?LwsStorageInterface $lws_storage = NULL, ?LwsAccessRecordInterface $lws_access = NULL, string $decision = 'deny'): array {
    if ($lws_storage === NULL || $lws_access === NULL || $lws_access->getKind() !== LwsAccessRecordInterface::REQUEST || $lws_access->getStorageId() !== (int) $lws_storage->id()) {
      throw new NotFoundHttpException();
    }
    $this->storage = $lws_storage;
    $this->request = $lws_access;
    $this->approve = $decision === 'approve';
    $form = parent::buildForm($form, $form_state);
    $form['document'] = [
      '#type' => 'details',
      '#title' => $this->t('The request'),
      '#weight' => -10,
      'json' => [
        '#type' => 'html_tag',
        '#tag' => 'pre',
        '#value' => json_encode($lws_access->getDocument(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
      ],
    ];
    return $form;
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
    $ref = $this->storages->ref($this->storage);
    if ($this->approve) {
      try {
        $grant = $this->records->approve($ref, $this->request, (int) $this->currentUser()->id());
      }
      catch (InvalidPolicyException $e) {
        $this->messenger()->addError($this->t('The request can no longer be granted: @reason', ['@reason' => $e->getMessage()]));
        $form_state->setRedirectUrl($this->getCancelUrl());
        return;
      }
      $this->messenger()->addStatus($this->t('Granted: @uri', ['@uri' => $this->records->uri($ref, $grant)]));
    }
    else {
      $this->records->delete($ref, $this->request);
      $this->messenger()->addStatus($this->t('The request was denied.'));
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
