<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\lws_authz\Entity\TrustedAuthorizationServer;
use Drupal\lws_authz\Entity\TrustedAuthorizationServerInterface;
use Drupal\lws_authz\Server\LocalAuthorizationServer;
use Drupal\lws_authz\Token\JsonWebKeySet;

/**
 * Adds and edits trusted authorization servers.
 */
final class TrustedAuthorizationServerForm extends EntityForm {

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
    $server = $this->entity;
    assert($server instanceof TrustedAuthorizationServerInterface);

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $server->label(),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $server->id(),
      '#machine_name' => [
        'exists' => [self::class, 'idExists'],
      ],
      '#disabled' => !$server->isNew(),
    ];
    $form['issuer'] = [
      '#type' => 'url',
      '#title' => $this->t('Issuer'),
      '#description' => $this->t('The issuer identifier of the server: the "iss" of its access tokens, and the "as_uri" that 401 challenges send clients to.'),
      '#default_value' => $server->getIssuer(),
      '#required' => TRUE,
    ];
    $form['jwks'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Pinned keys'),
      '#description' => $this->t('A JSON Web Key Set with the public keys that sign its tokens. Leave empty to fetch them from the "jwks_uri" of its metadata at /.well-known/lws-configuration, which also picks up rotated keys. Only public key members are kept.'),
      '#default_value' => $server->getJwks(),
      '#rows' => 6,
    ];
    $form['default'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Default for storages'),
      '#description' => $this->t("Storages that name no authorization server trust this one, instead of this site's own."),
      '#default_value' => !$server->isNew() && $server->id() === $this->config('lws_authz.settings')->get('authorization_server'),
    ];
    return $form;
  }

  /**
   * Whether a machine name is taken; "local" names this site's own server.
   */
  public static function idExists(string $id): bool {
    return $id === LocalAuthorizationServer::ID || TrustedAuthorizationServer::load($id) !== NULL;
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
    parent::validateForm($form, $form_state);
    $issuer = (string) $form_state->getValue('issuer');
    $parts = parse_url($issuer);
    if (!in_array($parts['scheme'] ?? '', ['https', 'http'], TRUE) || isset($parts['query']) || isset($parts['fragment'])) {
      $form_state->setErrorByName('issuer', $this->t('The issuer must be an HTTPS URL, or an HTTP one in development, without a query or fragment (RFC 8414 §2).'));
    }
    $jwks = trim((string) $form_state->getValue('jwks'));
    if ($jwks === '') {
      $form_state->setValue('jwks', NULL);
      return;
    }
    try {
      $keys = JsonWebKeySet::parse($jwks);
    }
    catch (\InvalidArgumentException $e) {
      $form_state->setErrorByName('jwks', $e->getMessage());
      return;
    }
    if ($keys->count() === 0) {
      $form_state->setErrorByName('jwks', $this->t('The key set has no signing keys of a kind this site verifies: EC P-256 or P-384, Ed25519, or RSA of 2048 bits or more.'));
      return;
    }
    $form_state->setValue('jwks', json_encode($keys->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
  }

  /**
   * {@inheritdoc}
   *
   * The "default" checkbox is site configuration, not a server property.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
    assert($entity instanceof TrustedAuthorizationServerInterface);
    foreach ($form_state->getValues() as $key => $value) {
      if ($key !== 'default') {
        $entity->set($key, $value);
      }
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
  public function save(array $form, FormStateInterface $form_state): int {
    $status = parent::save($form, $form_state);
    $settings = $this->configFactory()->getEditable('lws_authz.settings');
    $id = (string) $this->entity->id();
    if ($form_state->getValue('default')) {
      $settings->set('authorization_server', $id)->save();
    }
    elseif ($settings->get('authorization_server') === $id) {
      $settings->set('authorization_server', LocalAuthorizationServer::ID)->save();
    }
    $this->messenger()->addStatus($status === SAVED_NEW
      ? $this->t('Added the authorization server %label.', ['%label' => (string) $this->entity->label()])
      : $this->t('Saved the authorization server %label.', ['%label' => (string) $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $status;
  }

}
