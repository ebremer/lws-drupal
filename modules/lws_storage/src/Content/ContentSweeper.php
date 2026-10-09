<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Content;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileUsage\FileUsageInterface;

/**
 * Finds and deletes LWS content that nothing refers to.
 *
 * Two things can be left behind: file entities whose resource is gone and
 * that nothing else uses, and bytes without a file entity, from a process
 * that died between writing content and committing it.
 */
final class ContentSweeper {

  /**
   * How old bytes without a file entity must be to be deleted, in seconds.
   *
   * Younger ones may belong to a write that has not committed yet.
   */
  public const GRACE = 3600;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly FileUsageInterface $fileUsage,
    private readonly FileSystemInterface $fileSystem,
    private readonly ContentStore $content,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Deletes unused file entities and unreferenced bytes.
   *
   * @return array{files: int, bytes: int}
   *   How many file entities and stray files were deleted.
   */
  public function sweep(): array {
    $directory = $this->content->scheme() . '://' . ContentStore::DIRECTORY;
    // LIKE ignores case on PostgreSQL and SQLite, where it would also match
    // another module's "private://LWS/…".
    $known = array_filter(
      $this->database->select('file_managed', 'f')
        ->fields('f', ['uri', 'fid'])
        ->condition('uri', $this->database->escapeLike($directory . '/') . '%', 'LIKE')
        ->execute()
        ?->fetchAllKeyed() ?? [],
      static fn ($uri): bool => str_starts_with((string) $uri, $directory . '/'),
      ARRAY_FILTER_USE_KEY,
    );

    $files = 0;
    $storage = $this->entityTypeManager->getStorage('file');
    foreach (array_chunk(array_values($known), 100) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $file) {
        if ($this->fileUsage->listUsage($file) === []) {
          $file->delete();
          $files++;
        }
      }
    }

    $bytes = 0;
    if (is_dir($directory)) {
      $cutoff = $this->time->getRequestTime() - self::GRACE;
      foreach ($this->fileSystem->scanDirectory($directory, '/.*/') as $uri => $info) {
        if (!isset($known[$uri]) && (int) @filemtime($uri) < $cutoff) {
          $this->fileSystem->delete($uri);
          $bytes++;
        }
      }
    }
    return ['files' => $files, 'bytes' => $bytes];
  }

}
