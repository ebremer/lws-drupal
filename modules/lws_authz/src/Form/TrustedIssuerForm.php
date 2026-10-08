<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\lws_authz\Entity\TrustedIssuer;
use Drupal\lws_authz\Entity\TrustedIssuerInterface;
use Drupal\lws_authz\Token\JsonWebKeySet;

/**
 * Adds or edits a trusted OpenID Provider.
 */
final class TrustedIssuerForm extends EntityForm {

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
    $provider = $this->entity;
    assert($provider instanceof TrustedIssuerInterface);

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $provider->label(),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $provider->id(),
      '#machine_name' => [
        'exists' => [TrustedIssuer::class, 'load'],
      ],
      '#disabled' => !$provider->isNew(),
    ];
    $form['issuer'] = [
      '#type' => 'url',
      '#title' => $this->t('Issuer'),
      '#description' => $this->t('The issuer identifier of the provider: the "iss" of its ID Tokens, such as <em>https://keycloak.example/realms/main</em>.'),
      '#default_value' => $provider->getIssuer(),
      '#required' => TRUE,
    ];
    $form['jwks'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Pinned keys'),
      '#description' => $this->t('A JSON Web Key Set with the public keys that sign its ID Tokens. Leave empty to discover them from its OpenID Connect Discovery document, which also picks up rotated keys.'),
      '#default_value' => $provider->getJwks(),
      '#rows' => 6,
    ];
    $form['require_as_audience'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require this authorization server in "aud"'),
      '#description' => $this->t("An ID Token that does not name this site's authorization server among its audiences is refused. Without it, the ID Token's \"aud\" must include its \"azp\", the client it was issued to, so that any client the provider issued tokens to may exchange them here (lws10-authn-openid, OpenID Connect Core §3.1.3.7)."),
      '#default_value' => $provider->requiresAsAudience(),
    ];
    $form['verify_subject'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("Require each subject's document to name this provider"),
      '#description' => $this->t('The subject of an ID Token is dereferenced, and its controlled identifier document must name this provider as its OpenID Provider. Without it, the provider is trusted to assert any subject, so that anyone who can choose what it puts in "sub" can act as anyone.'),
      '#default_value' => $provider->verifiesSubject(),
    ];
    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $provider->status(),
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
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $issuer = (string) $form_state->getValue('issuer');
    $parts = parse_url($issuer);
    if (!in_array($parts['scheme'] ?? '', ['https', 'http'], TRUE) || isset($parts['query']) || isset($parts['fragment'])) {
      $form_state->setErrorByName('issuer', $this->t('The issuer must be an HTTPS URL, or an HTTP one in development, without a query or fragment (OpenID Connect Core §2).'));
    }
    $others = $this->entityTypeManager->getStorage('lws_trusted_issuer')->loadByProperties(['issuer' => $issuer]);
    unset($others[(string) $this->entity->id()]);
    if ($others !== []) {
      $form_state->setErrorByName('issuer', $this->t('Another provider has this issuer.'));
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
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $status = parent::save($form, $form_state);
    $arguments = ['%label' => (string) $this->entity->label()];
    $this->messenger()->addStatus($status === SAVED_NEW
      ? $this->t('Added the OpenID Provider %label.', $arguments)
      : $this->t('Saved the OpenID Provider %label.', $arguments));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $status;
  }

}
