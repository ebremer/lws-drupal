<?php

declare(strict_types=1);

namespace Drupal\lws_notify\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\lws_notify\Entity\LwsSubscriptionInterface;
use Drupal\lws_notify\Subscriptions;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Cancels a subscription to a storage, as its subscriber's DELETE would.
 */
final class SubscriptionCancelForm extends ConfirmFormBase {

  /**
   * The storage.
   */
  protected LwsStorageInterface $storage;

  /**
   * The subscription.
   */
  protected LwsSubscriptionInterface $subscription;

  public function __construct(
    protected Subscriptions $subscriptions,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('lws_notify.subscriptions'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lws_notify_subscription_cancel';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Cancel the subscription of @agent to @storage?', [
      '@agent' => $this->subscription->getAgent(),
      '@storage' => (string) $this->storage->label(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('Notifications to @inbox stop. The subscriber may subscribe again.', ['@inbox' => $this->subscription->getInbox()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Cancel subscription');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelText(): TranslatableMarkup {
    return $this->t('Keep it');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('lws_notify.storage_subscriptions', ['lws_storage' => $this->storage->id()]);
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
   * @param \Drupal\lws_notify\Entity\LwsSubscriptionInterface|null $lws_subscription
   *   The subscription, which must be the storage's.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?LwsStorageInterface $lws_storage = NULL, ?LwsSubscriptionInterface $lws_subscription = NULL): array {
    if ($lws_storage === NULL || $lws_subscription === NULL || $lws_subscription->getStorageId() !== (int) $lws_storage->id()) {
      throw new NotFoundHttpException();
    }
    $this->storage = $lws_storage;
    $this->subscription = $lws_subscription;
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
    $this->subscriptions->delete($this->subscription);
    $this->messenger()->addStatus($this->t('The subscription was cancelled.'));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
