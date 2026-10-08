<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Hook;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\lws\Http\RequestBody;
use Drupal\lws_storage\Content\ContentStore;

/**
 * Status report entries for LWS storages.
 */
final class LwsStorageRequirements {

  use StringTranslationTrait;

  public function __construct(
    private readonly ContentStore $content,
    private readonly StreamWrapperManagerInterface $streamWrappers,
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
      'lws_storage_private_files' => $this->fileSystem(),
      'lws_storage_upload_size' => $this->uploadSize(),
    ];
  }

  /**
   * The entry for the file system that holds content.
   *
   * @return array<string, mixed>
   *   The requirement.
   */
  private function fileSystem(): array {
    $scheme = $this->content->scheme();
    $title = $this->t('LWS resource content');
    if (!$this->content->isAvailable()) {
      return [
        'title' => $title,
        'value' => $this->t('No @scheme file system', ['@scheme' => $scheme]),
        'description' => $scheme === 'private'
          ? $this->t('The content of data resources is kept in the private file system, so data resources cannot be created. Set <code>@setting</code> in settings.php.', ['@setting' => "\$settings['file_private_path']"])
          : $this->t('The stream wrapper that lws_storage.settings:scheme names is not available, so data resources cannot be created.'),
        'severity' => RequirementSeverity::Error,
      ];
    }
    $class = $this->streamWrappers->getClass($scheme);
    if (is_string($class) && is_a($class, PublicStream::class, TRUE)) {
      return [
        'title' => $title,
        'value' => $this->t('In @scheme://lws', ['@scheme' => $scheme]),
        'description' => $this->t('The web server serves the @scheme file system itself, so anyone who learns where a resource\'s content is can download it, whatever LWS policy says. <a href=":url">Keep content</a> in the private file system.', [
          '@scheme' => $scheme,
          ':url' => Url::fromRoute('lws_storage.settings')->toString(),
        ]),
        'severity' => RequirementSeverity::Error,
      ];
    }
    return [
      'title' => $title,
      'value' => $this->t('In @scheme://lws', ['@scheme' => $scheme]),
      'severity' => RequirementSeverity::OK,
    ];
  }

  /**
   * The entry for the size of content one request may write.
   *
   * Content is the request body itself. PHP's post_max_size limits it for
   * POST, which creates, but not for PUT, which replaces; the web server may
   * limit both, as Apache's LimitRequestBody, 1 GB by default, does.
   *
   * @return array<string, mixed>
   *   The requirement.
   */
  private function uploadSize(): array {
    $limit = $this->content->maxBytes();
    $post = RequestBody::postMaxSize();
    $arguments = [
      '@limit' => ByteSizeMarkup::create($limit),
      '@post' => $post > 0 ? ByteSizeMarkup::create($post) : $this->t('unlimited'),
      ':url' => Url::fromRoute('lws_storage.settings')->toString(),
    ];
    $requirement = ['title' => $this->t('LWS content size')];
    if ($limit === 0) {
      return $requirement + [
        'value' => $this->t('No limit'),
        'description' => $this->t('Creating a resource is limited by PHP\'s post_max_size (@post), but replacing one only by the quota and the web server. <a href=":url">Set the largest content</a> one request may write.', $arguments),
        'severity' => RequirementSeverity::Warning,
      ];
    }
    if ($post > 0 && $limit > $post) {
      return $requirement + [
        'value' => $this->t('@limit', $arguments),
        'description' => $this->t('PHP\'s post_max_size is @post, so creating a resource larger than that fails with 413 Content Too Large, though replacing one works. Raise post_max_size, or <a href=":url">lower the largest content</a>.', $arguments),
        'severity' => RequirementSeverity::Warning,
      ];
    }
    return $requirement + [
      'value' => $this->t('@limit', $arguments),
      'description' => $this->t('The web server must accept request bodies this large, too.'),
      'severity' => RequirementSeverity::OK,
    ];
  }

}
