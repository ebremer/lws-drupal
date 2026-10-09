<?php

declare(strict_types=1);

namespace Drupal\lws_identity\Form;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\lws_identity\AgentKeys;
use Drupal\lws_identity\InvalidAgentKeyException;
use Drupal\user\UserInterface;

/**
 * Adds a public key to a user's agent.
 */
final class AgentKeyAddForm extends FormBase {

  use AutowireTrait;

  public function __construct(
    protected AgentKeys $keys,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lws_identity_key_add';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\user\UserInterface|null $user
   *   The user whose agent gets the key.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?UserInterface $user = NULL): array {
    $form_state->set('user', $user);
    $form['jwk'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Public key'),
      '#description' => $this->t('A public JSON Web Key: EC P-256 or P-384, OKP Ed25519, or RSA of at least 2048 bits. Paste only the public key, never the private one. Its <code>kid</code>, if it has one, becomes the key ID; otherwise the key ID is its thumbprint.'),
      '#required' => TRUE,
      '#rows' => 8,
      '#attributes' => ['spellcheck' => 'false'],
    ];
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#description' => $this->t('What the key is for, such as the device or program that holds it.'),
      '#maxlength' => 128,
    ];
    $form['expires'] = [
      '#type' => 'date',
      '#title' => $this->t('Expires'),
      '#description' => $this->t('The key stops working at the start of this day, in UTC. Leave empty for a key that does not expire.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add key'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    try {
      AgentKeys::publicJwk(AgentKeys::decode((string) $form_state->getValue('jwk')));
    }
    catch (InvalidAgentKeyException $e) {
      $form_state->setErrorByName('jwk', $e->getMessage());
    }
    $expires = self::expires((string) $form_state->getValue('expires'));
    if ($expires === FALSE) {
      $form_state->setErrorByName('expires', $this->t('Enter a date.'));
    }
    elseif ($expires !== NULL && $expires <= time()) {
      $form_state->setErrorByName('expires', $this->t('Enter a date in the future.'));
    }
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
    $user = $form_state->get('user');
    assert($user instanceof UserInterface);
    $expires = self::expires((string) $form_state->getValue('expires'));
    try {
      $key = $this->keys->add($user, (string) $form_state->getValue('jwk'), (string) $form_state->getValue('label'), is_int($expires) ? $expires : NULL);
    }
    catch (InvalidAgentKeyException $e) {
      $this->messenger()->addError($e->getMessage());
      $form_state->setRebuild();
      return;
    }
    $this->messenger()->addStatus($this->t('The key %kid was added.', ['%kid' => $key->getKeyId()]));
    $form_state->setRedirect('lws_identity.user', ['user' => $user->id()]);
  }

  /**
   * The Unix time at the start of a date, in UTC.
   *
   * @return int|false|null
   *   The time, NULL for no date, FALSE for one that is not a date.
   */
  private static function expires(string $date): int|false|null {
    if ($date === '') {
      return NULL;
    }
    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
    return $parsed === FALSE ? FALSE : $parsed->getTimestamp();
  }

}
