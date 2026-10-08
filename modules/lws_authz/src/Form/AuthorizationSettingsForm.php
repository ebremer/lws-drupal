<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\lws_authz\AuthenticationSuite\AuthenticationSuiteManager;
use Drupal\lws_authz\Server\LocalAuthorizationServer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings of LWS authorization and of this site's authorization server.
 */
final class AuthorizationSettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AuthenticationSuiteManager $suites,
    protected LocalAuthorizationServer $local,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.lws_authentication_suite'),
      $container->get('lws_authz.local_server'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lws_authz_settings';
  }

  /**
   * {@inheritdoc}
   *
   * @return list<string>
   *   The configuration names.
   */
  protected function getEditableConfigNames(): array {
    return ['lws_authz.settings'];
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
    $settings = $this->config('lws_authz.settings');
    $servers = [LocalAuthorizationServer::ID => $this->t("This site's own (@issuer)", ['@issuer' => $this->local->getIssuer()])];
    foreach ($this->entityTypeManager->getStorage('lws_trusted_as')->loadMultiple() as $id => $server) {
      $servers[$id] = (string) $server->label();
    }
    $default = (string) $settings->get('authorization_server');
    $form['authorization_server'] = [
      '#type' => 'select',
      '#title' => $this->t('Default authorization server'),
      '#description' => $this->t('The server whose access tokens storages that name none accept.'),
      '#options' => $servers,
      '#default_value' => $default === '' ? LocalAuthorizationServer::ID : $default,
      '#required' => TRUE,
    ];
    $form['clock_skew'] = [
      '#type' => 'number',
      '#title' => $this->t('Clock skew'),
      '#description' => $this->t('How far apart clocks may be when the times in access tokens and credentials are checked.'),
      '#field_suffix' => $this->t('seconds'),
      '#min' => 0,
      '#max' => 300,
      '#default_value' => $settings->get('clock_skew'),
      '#required' => TRUE,
    ];

    $form['local'] = [
      '#type' => 'details',
      '#title' => $this->t("This site's authorization server"),
      '#open' => TRUE,
      '#description' => $this->t('Its metadata is at <a href=":url">:url</a>.', [':url' => $this->local->getIssuer() . LocalAuthorizationServer::METADATA_PATH]),
    ];
    $options = [];
    foreach ($this->suites->getDefinitions() as $id => $definition) {
      $options[$id] = $definition['label'];
    }
    $form['local']['suites'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Authentication suites'),
      '#description' => $this->t('The credentials it exchanges for access tokens.'),
      '#options' => $options,
      '#default_value' => (array) $settings->get('suites'),
    ];
    $form['local']['openid'] = [
      '#type' => 'details',
      '#title' => $this->t('OpenID Connect'),
      '#open' => TRUE,
      '#description' => $this->t('ID Tokens are accepted from the <a href=":url">OpenID Providers configured</a> here, as each is configured, and from others as below.', [
        ':url' => Url::fromRoute('entity.lws_trusted_issuer.collection')->toString(),
      ]),
      '#states' => [
        'visible' => [':input[name="suites[openid]"]' => ['checked' => TRUE]],
      ],
    ];
    $form['local']['openid']['openid_discovery'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("Trust the provider a subject's document names"),
      '#description' => $this->t("Accept an ID Token from a provider that is not configured when its subject's controlled identifier document names the provider as its OpenID Provider (lws10-authn-openid §5). Without it, only configured providers are trusted."),
      '#default_value' => (bool) $settings->get('openid.discovery'),
    ];
    $form['local']['openid']['openid_require_as_audience'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require such ID Tokens to name this authorization server in "aud"'),
      '#description' => $this->t('Without it, an ID Token issued to any client of such a provider can be exchanged here by that client.'),
      '#default_value' => (bool) $settings->get('openid.require_as_audience'),
      '#states' => [
        'visible' => [':input[name="openid_discovery"]' => ['checked' => TRUE]],
      ],
    ];
    $form['local']['token_lifetime'] = [
      '#type' => 'number',
      '#title' => $this->t('Access token lifetime'),
      '#description' => $this->t('LWS recommends 300 seconds or less. A token never outlives the credential it was issued for.'),
      '#field_suffix' => $this->t('seconds'),
      '#min' => 30,
      '#max' => 3600,
      '#default_value' => $settings->get('token_lifetime'),
      '#required' => TRUE,
    ];
    $form['local']['rate_limit_client'] = [
      '#type' => 'number',
      '#title' => $this->t('Token requests per minute from one address'),
      '#min' => 1,
      '#default_value' => $settings->get('rate_limits.client'),
      '#required' => TRUE,
    ];
    $form['local']['rate_limit_subject'] = [
      '#type' => 'number',
      '#title' => $this->t('Tokens per minute for one agent'),
      '#min' => 1,
      '#default_value' => $settings->get('rate_limits.subject'),
      '#required' => TRUE,
    ];
    $form['rate_limit_access_requests'] = [
      '#type' => 'number',
      '#title' => $this->t('Access requests per hour from one agent'),
      '#description' => $this->t('How many access requests one agent may submit to the storages of this site.'),
      '#min' => 1,
      '#default_value' => $settings->get('rate_limits.access_requests'),
      '#required' => TRUE,
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
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('lws_authz.settings')
      ->set('authorization_server', (string) $form_state->getValue('authorization_server'))
      ->set('clock_skew', (int) $form_state->getValue('clock_skew'))
      ->set('suites', array_values(array_filter((array) $form_state->getValue('suites'), 'is_string')))
      ->set('token_lifetime', (int) $form_state->getValue('token_lifetime'))
      ->set('rate_limits.client', (int) $form_state->getValue('rate_limit_client'))
      ->set('rate_limits.subject', (int) $form_state->getValue('rate_limit_subject'))
      ->set('rate_limits.access_requests', (int) $form_state->getValue('rate_limit_access_requests'))
      ->set('openid.discovery', (bool) $form_state->getValue('openid_discovery'))
      ->set('openid.require_as_audience', (bool) $form_state->getValue('openid_require_as_audience'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
