<?php

declare(strict_types=1);

namespace Drupal\lws_identity\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings of agent identities: OpenID Providers, storage provisioning.
 */
final class IdentitySettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lws_identity_settings';
  }

  /**
   * {@inheritdoc}
   *
   * @return list<string>
   *   The configuration names.
   */
  protected function getEditableConfigNames(): array {
    return ['lws_identity.settings'];
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
      '#markup' => '<p>' . $this->t('Users with the <em>Have an LWS agent identity</em> permission have an agent URI, whose controlled identifier document names their keys and the OpenID Providers below. Users manage their keys on the <em>LWS identity</em> tab of their account.') . '</p>',
    ];
    $form['openid_providers'] = [
      '#type' => 'textarea',
      '#title' => $this->t('OpenID Providers'),
      '#description' => $this->t('Issuer identifiers, one per line, that every agent&rsquo;s document names as its OpenID Provider (LWS OpenID Connect suite). An ID Token one of them issues whose <code>sub</code> is an agent URI then stands for that agent, at this site and at any other, so list only providers that issue such tokens for this site&rsquo;s users, and no others.'),
      '#rows' => 4,
      '#config_target' => new ConfigTarget(
        'lws_identity.settings',
        'openid_providers',
        fromConfig: static fn (?array $providers): string => implode("\n", $providers ?? []),
        toConfig: static fn (?string $text): array => self::lines((string) $text),
      ),
    ];
    $form['provisioning'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Create a storage for each new agent'),
      '#description' => $this->t('When an account first has an agent, it gets a storage named after the user, controlled by the agent and owned by the user. This needs the LWS Storage module and the canonical base URL. A storage an administrator deletes is not created again.'),
      '#config_target' => 'lws_identity.settings:provisioning.storage',
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
    foreach (self::lines((string) $form_state->getValue('openid_providers')) as $issuer) {
      $scheme = strtolower((string) parse_url($issuer, PHP_URL_SCHEME));
      if (!in_array($scheme, ['https', 'http'], TRUE) || parse_url($issuer, PHP_URL_HOST) === NULL || str_contains($issuer, '#') || str_contains($issuer, '?')) {
        $form_state->setErrorByName('openid_providers', $this->t('%issuer is not an issuer identifier: an HTTPS URL without a query or fragment.', ['%issuer' => $issuer]));
      }
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * The non-empty lines of a text, trimmed, without repeats.
   *
   * @return list<string>
   *   The lines.
   */
  private static function lines(string $text): array {
    return array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', $text) ?: []), static fn (string $line): bool => $line !== '')));
  }

}
