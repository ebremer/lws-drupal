<?php

declare(strict_types=1);

namespace Drupal\lws_identity\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\lws_identity\Entity\LwsAgentKeyInterface;
use Drupal\user\UserInterface;

/**
 * Removes a key from a user's agent.
 */
final class AgentKeyDeleteForm extends ConfirmFormBase {

  /**
   * The user whose agent has the key.
   */
  protected ?UserInterface $user = NULL;

  /**
   * The key.
   */
  protected ?LwsAgentKeyInterface $key = NULL;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lws_identity_key_delete';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\user\UserInterface|null $user
   *   The user whose agent has the key.
   * @param \Drupal\lws_identity\Entity\LwsAgentKeyInterface|null $lws_agent_key
   *   The key.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?UserInterface $user = NULL, ?LwsAgentKeyInterface $lws_agent_key = NULL): array {
    $this->user = $user;
    $this->key = $lws_agent_key;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Remove the key %label (%kid)?', [
      '%label' => (string) $this->key?->label(),
      '%kid' => (string) $this->key?->getKeyId(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('Credentials signed with it stop working here at once, and at other authorization servers when they next read the agent&rsquo;s document, within minutes. This cannot be undone.');
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
    return Url::fromRoute('lws_identity.user', ['user' => $this->user?->id()]);
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->key !== NULL) {
      $kid = $this->key->getKeyId();
      $this->key->delete();
      $this->messenger()->addStatus($this->t('The key %kid was removed.', ['%kid' => $kid]));
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
