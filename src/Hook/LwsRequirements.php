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
    $base = (string) $this->configFactory->get('lws.settings')->get('base_url');
    $settings = Url::fromRoute('lws.settings')->toString();
    if ($base === '') {
      return [
        'lws_base_url' => [
          'title' => $this->t('LWS base URL'),
          'value' => $this->t('Not set'),
          'description' => $this->t('Storage URIs are taken from the Host header of each request. Set a canonical <a href=":url">base URL</a> before production use.', [':url' => $settings]),
          'severity' => RequirementSeverity::Warning,
        ],
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
    return ['lws_base_url' => $requirement];
  }

}
