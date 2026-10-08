<?php

declare(strict_types=1);

namespace Drupal\lws_index;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lws_storage\Entity\LwsResourceInterface;
use Drupal\lws_storage\ResourceLinks;
use Ebremer\Lws\Http\LinkHeader;
use Ebremer\Lws\LinkRelation;

/**
 * Keeps the index of resource types and links that searches look up.
 *
 * The index holds, for each resource, its types (its LWS class and those its
 * clients declared) and the targets of every other relation its clients set
 * (lws10-index, "Type and Relation Derivation"). Which of those relations a
 * search may filter on is settled when it runs (Relations), so changing the
 * setting needs no rebuild.
 *
 * Hooks call it as each resource is saved or deleted, inside the same
 * transaction: the index is never behind, and a change that rolls back takes
 * its index rows with it. The specification lets the index lag (DESIGN.md
 * §7.2); this one does not need to.
 */
final class Indexer {

  /**
   * The index table.
   */
  public const TABLE = 'lws_index_link';

  /**
   * The longest relation the index holds, in bytes.
   */
  public const MAX_REL_BYTES = 255;

  /**
   * The longest target the index holds, in bytes: the longest a client can set.
   */
  public const MAX_HREF_BYTES = 2048;

  /**
   * The resources a rebuild loads at once.
   */
  private const CHUNK = 200;

  public function __construct(
    private readonly Connection $database,
    private readonly ResourceLinks $links,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * The hash that stands for a relation and a target in the index.
   */
  public static function hash(string $rel, string $href): string {
    return hash('sha256', $rel . "\n" . $href);
  }

  /**
   * A relation as the index holds it.
   *
   * Registered relation types compare case-insensitively, so they are held
   * in lower case; extension relations, URIs, as they are (RFC 8288 §2.1).
   *
   * @return string|null
   *   The relation; NULL for one the index cannot hold.
   */
  public static function normalizeRel(string $rel): ?string {
    $rel = LinkHeader::normalizeRel($rel);
    return $rel !== '' && strlen($rel) <= self::MAX_REL_BYTES && preg_match('/^[\x21-\x7E]+$/', $rel) === 1 ? $rel : NULL;
  }

  /**
   * What the index holds for a resource.
   *
   * @return array<string, array{rel: string, href: string}>
   *   Relations and targets, keyed by their hash.
   */
  public function entries(LwsResourceInterface $resource): array {
    $entries = [];
    $add = static function (string $rel, string $href) use (&$entries): void {
      // Clients can set nothing else; the index could not hold it.
      if (strlen($href) <= self::MAX_HREF_BYTES && preg_match('/^[\x21-\x7E]+$/', $href) === 1) {
        $entries[self::hash($rel, $href)] = ['rel' => $rel, 'href' => $href];
      }
    };
    foreach ($this->links->types($resource) as $type) {
      $add(LinkRelation::TYPE, $type);
    }
    foreach ($resource->getUserMetadata()->links as $rel => $targets) {
      $rel = self::normalizeRel((string) $rel);
      if ($rel === NULL || $rel === LinkRelation::TYPE) {
        continue;
      }
      foreach ($targets as $target) {
        if (is_string($target['href'] ?? NULL)) {
          $add($rel, $target['href']);
        }
      }
    }
    return $entries;
  }

  /**
   * Indexes a resource that was created or changed.
   *
   * Writes only what changed, as most changes are to content, not links.
   */
  public function index(LwsResourceInterface $resource): void {
    $id = (int) $resource->id();
    $entries = $this->entries($resource);
    $held = $this->database->select(self::TABLE, 'l')
      ->fields('l', ['hash'])
      ->condition('resource_id', $id)
      ->execute()
      ?->fetchCol() ?? [];
    $gone = array_values(array_diff($held, array_keys($entries)));
    if ($gone !== []) {
      $this->database->delete(self::TABLE)
        ->condition('resource_id', $id)
        ->condition('hash', $gone, 'IN')
        ->execute();
    }
    $new = array_diff_key($entries, array_flip($held));
    if ($new !== []) {
      $insert = $this->database->insert(self::TABLE)->fields(['resource_id', 'storage_id', 'rel', 'href', 'hash']);
      foreach ($new as $hash => $entry) {
        $insert->values([$id, $resource->getLwsStorageId(), $entry['rel'], $entry['href'], $hash]);
      }
      $insert->execute();
    }
  }

  /**
   * Forgets a resource that was deleted.
   */
  public function remove(int $resourceId): void {
    $this->database->delete(self::TABLE)->condition('resource_id', $resourceId)->execute();
  }

  /**
   * Forgets every resource of a storage that was deleted.
   */
  public function removeStorage(int $storageId): void {
    $this->database->delete(self::TABLE)->condition('storage_id', $storageId)->execute();
  }

  /**
   * Indexes every resource again, as when the module is installed.
   *
   * @param \Closure(int): void|null $progress
   *   Told how many resources have been indexed, after each chunk.
   *
   * @return int
   *   The resources indexed.
   */
  public function rebuild(?\Closure $progress = NULL): int {
    $this->database->truncate(self::TABLE)->execute();
    $storage = $this->entityTypeManager->getStorage('lws_resource');
    $done = 0;
    $after = 0;
    while (TRUE) {
      $ids = $this->database->select('lws_resource', 'r')
        ->fields('r', ['id'])
        ->condition('id', $after, '>')
        ->orderBy('id')
        ->range(0, self::CHUNK)
        ->execute()
        ?->fetchCol() ?? [];
      if ($ids === []) {
        return $done;
      }
      foreach ($storage->loadMultiple($ids) as $resource) {
        if ($resource instanceof LwsResourceInterface) {
          $this->index($resource);
          $done++;
        }
      }
      $storage->resetCache($ids);
      $after = (int) end($ids);
      if ($progress !== NULL) {
        $progress($done);
      }
    }
  }

}
