<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Hook;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\lws_storage\Content\ContentStore;

/**
 * Status report entries for LWS storages.
 */
final class LwsStorageRequirements {

  use StringTranslationTrait;

  public function __construct(
    private readonly ContentStore $content,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   *
   * @return array<string, array<string, mixed>>
   *   The requirements.
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    $scheme = $this->content->scheme();
    if ($this->content->isAvailable()) {
      return [
        'lws_storage_private_files' => [
          'title' => $this->t('LWS resource content'),
          'value' => $this->t('In @scheme://lws', ['@scheme' => $scheme]),
          'severity' => RequirementSeverity::OK,
        ],
      ];
    }
    return [
      'lws_storage_private_files' => [
        'title' => $this->t('LWS resource content'),
        'value' => $this->t('No @scheme file system', ['@scheme' => $scheme]),
        'description' => $scheme === 'private'
          ? $this->t('The content of data resources is kept in the private file system, so data resources cannot be created. Set <code>@setting</code> in settings.php.', ['@setting' => "\$settings['file_private_path']"])
          : $this->t('The stream wrapper that lws_storage.settings:scheme names is not available, so data resources cannot be created.'),
        'severity' => RequirementSeverity::Error,
      ],
    ];
  }

}
