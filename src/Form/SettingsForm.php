<?php

declare(strict_types=1);

namespace Drupal\lws\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings of the LWS URL space.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * A path prefix: one or more lower-case segments, no trailing slash.
   */
  private const PREFIX = '/^(?:\/[a-z0-9][a-z0-9-]*)+$/';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lws_settings';
  }

  /**
   * {@inheritdoc}
   *
   * @return list<string>
   *   The configuration names.
   */
  protected function getEditableConfigNames(): array {
    return ['lws.settings'];
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
    $form['base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Canonical base URL'),
      '#description' => $this->t('The scheme, host and any base path that storage URIs start with, such as <em>https://storage.example</em>, with no trailing slash. It becomes part of every storage URI and access token audience. When empty, it is taken from each request, which trusts the Host header and is only safe in development.'),
      '#config_target' => 'lws.settings:base_url',
    ];
    $form['prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Path prefix'),
      '#description' => $this->t('Storages live under this path, such as <em>/lws</em> for <em>https://storage.example/lws/{storage}/</em>. Changing it changes every storage URI.'),
      '#config_target' => 'lws.settings:prefix',
      '#required' => TRUE,
    ];
    $form['conceal_existence'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Conceal existence'),
      '#description' => $this->t('Refuse agents with 404 Not Found rather than 403 Forbidden, so that they cannot tell whether a resource they may not access exists (LWS Core §9.5).'),
      '#config_target' => 'lws.settings:conceal_existence',
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
    $base = (string) $form_state->getValue('base_url');
    if ($base !== '') {
      $parts = parse_url($base);
      if (!in_array($parts['scheme'] ?? '', ['http', 'https'], TRUE) || isset($parts['query']) || isset($parts['fragment']) || str_ends_with($base, '/')) {
        $form_state->setErrorByName('base_url', $this->t('Use an http or https URL with no query, fragment or trailing slash.'));
      }
    }
    if (!preg_match(self::PREFIX, (string) $form_state->getValue('prefix'))) {
      $form_state->setErrorByName('prefix', $this->t('Use a path such as /lws: lower-case letters, digits and hyphens, starting with a slash and with no trailing slash.'));
    }
    parent::validateForm($form, $form_state);
  }

}
