<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\lws_storage\Content\ContentStore;

/**
 * Hook implementations of the storage module.
 */
final class LwsStorageHooks {

  public function __construct(
    private readonly ContentStore $content,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Implements hook_file_download().
   *
   * Core serves private files at /system/files/..., and the file module
   * grants access through any entity that references a file, which would let
   * Drupal entity access override LWS policy. LWS content is only ever served
   * through its LWS URL, so the download is refused to all but storage
   * administrators. A -1 from any module wins over every grant, and covers
   * image style derivatives too.
   */
  #[Hook('file_download')]
  public function fileDownload(string $uri): ?int {
    if ($this->content->owns($uri) && !$this->currentUser->hasPermission('administer lws storages')) {
      return -1;
    }
    return NULL;
  }

}
