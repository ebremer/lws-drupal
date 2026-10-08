<?php

declare(strict_types=1);

namespace Drupal\lws\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Status report entries for the LWS URL space.
 */
final class LwsRequirements {

  use StringTranslationTrait;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RequestStack $requestStack,
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
      'lws_base_url' => $this->baseUrl(),
      'lws_https' => $this->https(),
    ];
  }

  /**
   * The entry for the HTTPS of storage URIs.
   *
   * Access tokens are bearer tokens: anyone who sees one may use it until it
   * expires. Over plain HTTP, anyone on the way sees them.
   *
   * @return array<string, mixed>
   *   The requirement.
   */
  private function https(): array {
    $base = (string) $this->configFactory->get('lws.settings')->get('base_url');
    $request = $this->requestStack->getMainRequest();
    $origin = $base !== '' ? $base : ($request === NULL ? '' : $request->getSchemeAndHttpHost());
    $scheme = strtolower((string) parse_url($origin, PHP_URL_SCHEME));
    $host = strtolower(trim((string) parse_url($origin, PHP_URL_HOST), '[]'));
    $requirement = ['title' => $this->t('LWS over HTTPS'), 'value' => $origin];
    if ($scheme === 'https') {
      return $requirement + ['severity' => RequirementSeverity::OK];
    }
    $loopback = $host === 'localhost' || str_ends_with($host, '.localhost') || $host === '::1'
      || (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== FALSE && str_starts_with($host, '127.'));
    return $requirement + [
      'description' => $this->t('Storages are served over plain HTTP, so their access tokens, which anyone who sees may use, cross the network unprotected. Serve them over HTTPS, and give the canonical base URL an https scheme.'),
      'severity' => $loopback ? RequirementSeverity::Warning : RequirementSeverity::Error,
    ];
  }

  /**
   * The entry for the canonical base URL.
   *
   * @return array<string, mixed>
   *   The requirement.
   */
  private function baseUrl(): array {
    $base = (string) $this->configFactory->get('lws.settings')->get('base_url');
    $settings = Url::fromRoute('lws.settings')->toString();
    if ($base === '') {
      return [
        'title' => $this->t('LWS base URL'),
        'value' => $this->t('Not set'),
        'description' => $this->t('Storage URIs are taken from the Host header of each request. Set a canonical <a href=":url">base URL</a> before production use.', [':url' => $settings]),
        'severity' => RequirementSeverity::Warning,
      ];
    }
    $requirement = [
      'title' => $this->t('LWS base URL'),
      'value' => $base,
      'severity' => RequirementSeverity::OK,
    ];
    $request = $this->requestStack->getMainRequest();
    if ($request !== NULL && parse_url($base, PHP_URL_HOST) === $request->getHost()) {
      $requirement['description'] = $this->t('LWS shares its host with this site, so data resources are served from the same origin as administration pages. A separate host that never sees session cookies is recommended.');
      $requirement['severity'] = RequirementSeverity::Warning;
    }
    return $requirement;
  }

}
