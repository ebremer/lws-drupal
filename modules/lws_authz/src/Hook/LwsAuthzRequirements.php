<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Hook;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws_authz\AuthorizationServers;
use Drupal\lws_authz\Server\LocalAuthorizationServer;
use Drupal\lws_authz\Server\SigningKeys;

/**
 * Status report entries for LWS authorization.
 */
final class LwsAuthzRequirements {

  use StringTranslationTrait;

  public function __construct(
    private readonly AuthorizationServers $servers,
    private readonly LocalAuthorizationServer $local,
    private readonly SigningKeys $keys,
    private readonly LwsUrlGenerator $urls,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   *
   * @return array<string, array<string, mixed>>
   *   The requirements.
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    return [
      'lws_authz_default_server' => $this->defaultServer(),
      'lws_authz_local_server' => $this->localServer(),
    ];
  }

  /**
   * The entry for the server of storages that name none.
   *
   * @return array<string, mixed>
   *   The requirement.
   */
  private function defaultServer(): array {
    $id = $this->servers->defaultId();
    $default = $this->servers->default();
    if ($default !== NULL) {
      return [
        'title' => $this->t('LWS authorization server'),
        'value' => $this->t('@label (@issuer)', [
          '@label' => (string) $default->label(),
          '@issuer' => $default->getIssuer(),
        ]),
        'severity' => RequirementSeverity::OK,
      ];
    }
    return [
      'title' => $this->t('LWS authorization server'),
      'value' => $id === LocalAuthorizationServer::ID ? $this->t('This site, which has no signing key directory') : $this->t('Missing or disabled: @id', ['@id' => $id]),
      'description' => $this->t('Storages that name no authorization server accept no access tokens, and answer requests that need one with 503. Configure a signing key directory, or <a href=":url">choose another default server</a>.', [
        ':url' => Url::fromRoute('lws_authz.settings')->toString(),
      ]),
      'severity' => RequirementSeverity::Error,
    ];
  }

  /**
   * The entry for this site's own authorization server.
   *
   * @return array<string, mixed>
   *   The requirement.
   */
  private function localServer(): array {
    $title = $this->t("This site's LWS authorization server");
    $directory = $this->keys->directory();
    if ($directory === NULL) {
      return [
        'title' => $title,
        'value' => $this->t('No signing key directory'),
        'description' => $this->t("It cannot issue access tokens. Set <code>\$settings['lws_authz_key_directory']</code> in settings.php to a directory outside the web root, or configure the private file system."),
        'severity' => RequirementSeverity::Warning,
      ];
    }
    $basePath = (string) parse_url($this->urls->baseUrl(), PHP_URL_PATH);
    if (trim($basePath, '/') !== '') {
      return [
        'title' => $title,
        'value' => $this->local->getIssuer(),
        'description' => $this->t('The site lives at @path, but the metadata of its issuer must be at @url. Have the web server rewrite that URL to @path@metadata.', [
          '@path' => $basePath,
          '@url' => $this->local->getIssuer() . LocalAuthorizationServer::METADATA_PATH,
          '@metadata' => LocalAuthorizationServer::METADATA_PATH,
        ]),
        'severity' => RequirementSeverity::Warning,
      ];
    }
    $active = $this->keys->inventory()[0] ?? NULL;
    $arguments = ['@issuer' => $this->local->getIssuer(), '@directory' => $directory, '@kid' => $active['kid'] ?? ''];
    if ($active !== NULL && !is_readable($active['path'])) {
      return [
        'title' => $title,
        'value' => $this->t('Signing key @kid unreadable', $arguments),
        'description' => $this->t("The web server cannot read the active signing key in @directory, so no access tokens can be issued. Was it made by another user? Run <code>drush lws:key:rotate</code> as the web server's user.", $arguments),
        'severity' => RequirementSeverity::Error,
      ];
    }
    return [
      'title' => $title,
      'value' => $active === NULL
        ? $this->t('@issuer; its first signing key will be made in @directory when it is needed', $arguments)
        : $this->t('@issuer; signing with key @kid', $arguments),
      'severity' => RequirementSeverity::OK,
    ];
  }

}
