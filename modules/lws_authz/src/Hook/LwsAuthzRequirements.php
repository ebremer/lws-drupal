<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\lws_authz\AuthorizationServers;

/**
 * Status report entries for LWS authorization.
 */
final class LwsAuthzRequirements {

  use StringTranslationTrait;

  public function __construct(
    private readonly AuthorizationServers $servers,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   *
   * @return array<string, array<string, mixed>>
   *   The requirements.
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    $servers = Url::fromRoute('entity.lws_trusted_as.collection')->toString();
    $id = (string) $this->configFactory->get('lws_authz.settings')->get('authorization_server');
    $default = $this->servers->default();
    if ($default !== NULL) {
      return [
        'lws_authz_default_server' => [
          'title' => $this->t('LWS authorization server'),
          'value' => $this->t('@label (@issuer)', [
            '@label' => (string) $default->label(),
            '@issuer' => $default->getIssuer(),
          ]),
          'severity' => RequirementSeverity::OK,
        ],
      ];
    }
    return [
      'lws_authz_default_server' => [
        'title' => $this->t('LWS authorization server'),
        'value' => $id === '' ? $this->t('None') : $this->t('Missing or disabled: @id', ['@id' => $id]),
        'description' => $this->t('Storages that name no authorization server accept no access tokens, and answer requests that need one with 503. <a href=":url">Add a trusted authorization server</a> and make it the default.', [':url' => $servers]),
        'severity' => $id === '' ? RequirementSeverity::Warning : RequirementSeverity::Error,
      ],
    ];
  }

}
