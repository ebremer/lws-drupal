<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Hook;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Status report entries for LWS storages.
 */
final class LwsStorageRequirements {

  use StringTranslationTrait;

  /**
   * Implements hook_runtime_requirements().
   *
   * @return array<string, array<string, mixed>>
   *   The requirements.
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    if ((string) Settings::get('file_private_path', '') !== '') {
      return [
        'lws_storage_private_files' => [
          'title' => $this->t('LWS resource content'),
          'value' => $this->t('Private file system'),
          'severity' => RequirementSeverity::OK,
        ],
      ];
    }
    return [
      'lws_storage_private_files' => [
        'title' => $this->t('LWS resource content'),
        'value' => $this->t('No private file system'),
        'description' => $this->t('The content of data resources is kept in the private file system. Set <code>@setting</code> in settings.php.', ['@setting' => "\$settings['file_private_path']"]),
        'severity' => RequirementSeverity::Warning,
      ],
    ];
  }

}
