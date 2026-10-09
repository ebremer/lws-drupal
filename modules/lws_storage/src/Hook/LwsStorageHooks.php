<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Hook;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileInterface;
use Drupal\lws_storage\Content\ContentStore;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Hook implementations of the storage module.
 */
final class LwsStorageHooks {

  public function __construct(
    private readonly ContentStore $content,
    private readonly AccountInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_predelete() for user.
   *
   * LWS content outlives the account that wrote it: it is the storage's. Its
   * files go to Anonymous, as "reassign" does with nodes, so that none names
   * a user who is gone. Core leaves other files as they are.
   */
  #[Hook('user_predelete')]
  public function userPredelete(EntityInterface $user): void {
    $files = $this->database->select('file_managed', 'f')
      ->fields('f', ['fid', 'uri'])
      ->condition('uid', (int) $user->id())
      ->execute()
      ?->fetchAllKeyed() ?? [];
    $fids = array_keys(array_filter($files, fn ($uri): bool => $this->content->owns((string) $uri)));
    foreach (array_chunk($fids, 500) as $chunk) {
      $this->database->update('file_managed')->fields(['uid' => 0])->condition('fid', $chunk, 'IN')->execute();
    }
    if ($fids !== []) {
      $this->entityTypeManager->getStorage('file')->resetCache($fids);
    }
  }

  /**
   * Implements hook_file_download().
   *
   * Core serves private files at /system/files/..., and the file module
   * grants access through any entity that references a file, which would let
   * Drupal entity access override LWS policy. LWS content is only ever served
   * through its LWS URL, so the download is refused to all but storage
   * administrators. A -1 from any module wins over every grant, and covers
   * image style derivatives too.
   *
   * Administrators get the content as an attachment, in a sandbox: it is a
   * client's, and an HTML or SVG file opened as a page of this site would run
   * with their session.
   *
   * @return int|array<string, string>|null
   *   -1 to refuse, headers to add, or NULL for files that are not LWS
   *   content.
   */
  #[Hook('file_download')]
  public function fileDownload(string $uri): int|array|null {
    if (!$this->content->owns($uri)) {
      return NULL;
    }
    if (!$this->currentUser->hasPermission('administer lws storages')) {
      return -1;
    }
    $files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $uri]);
    $file = reset($files);
    $name = strtr($file instanceof FileInterface ? (string) $file->getFilename() : basename($uri), '/\\', '__');
    $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'content';
    return [
      'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, $fallback),
      'Content-Security-Policy' => 'sandbox',
      'X-Content-Type-Options' => 'nosniff',
    ];
  }

}
