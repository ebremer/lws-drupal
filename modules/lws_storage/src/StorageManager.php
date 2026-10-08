<?php

declare(strict_types=1);

namespace Drupal\lws_storage;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\file\Validation\FileValidatorInterface;
use Drupal\lws\Agent\RequestingAgent;
use Drupal\lws\Http\LwsHttpException;
use Drupal\lws\Routing\ResourceName;
use Drupal\lws_authz\Server\LocalAuthorizationServer;
use Drupal\lws_storage\Content\ContentStore;
use Drupal\lws_storage\Content\StoredContent;
use Drupal\lws_storage\Entity\LwsResourceInterface;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\Linkset\UserMetadata;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Creates, replaces and deletes storages and resources.
 *
 * Each operation is one database transaction (LWS Core §7.3). Content is
 * written to a new file before the transaction, and the transaction only
 * moves the resource's reference to it (DESIGN.md §5.3): readers never see a
 * half-written resource, and a failed transaction leaves the old content in
 * place and deletes the new bytes.
 */
final class StorageManager {

  /**
   * The queue of files to delete once nothing uses them.
   */
  public const GC_QUEUE = 'lws_storage_gc';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly ResourceRepository $resources,
    private readonly ContentStore $content,
    private readonly FileValidatorInterface $fileValidator,
    private readonly FileUsageInterface $fileUsage,
    private readonly QueueFactory $queueFactory,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly UuidInterface $uuid,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Creates a storage and its root container.
   *
   * @param string $slug
   *   The URI segment naming the storage.
   * @param string $label
   *   A human-readable name.
   * @param list<string> $controllers
   *   Agent URIs with full control of the storage.
   * @param int|null $ownerId
   *   The Drupal user who administers the storage.
   * @param string|null $authorizationServer
   *   The authorization server whose tokens it accepts, by ID: "local" for
   *   this site's own, or a trusted server's; NULL for the site's default.
   * @param int|null $quotaBytes
   *   The most content it may hold, in bytes; NULL for no limit.
   * @param int|null $pageSize
   *   The members on one page of a listing; NULL for the site default.
   *
   * @throws \InvalidArgumentException
   *   When the storage would be invalid, for example a taken or malformed slug.
   */
  public function createStorage(string $slug, string $label, array $controllers = [], ?int $ownerId = NULL, ?string $authorizationServer = NULL, ?int $quotaBytes = NULL, ?int $pageSize = NULL): LwsStorageInterface {
    if ($authorizationServer !== NULL && $authorizationServer !== LocalAuthorizationServer::ID && $this->entityTypeManager->getStorage('lws_trusted_as')->load($authorizationServer) === NULL) {
      throw new \InvalidArgumentException(sprintf('There is no trusted authorization server %s.', $authorizationServer));
    }
    $storage = $this->entityTypeManager->getStorage('lws_storage')->create([
      'slug' => $slug,
      'label' => $label,
      'controllers' => $controllers,
      'owner' => $ownerId,
      'authorization_server' => $authorizationServer,
      'quota_bytes' => $quotaBytes,
      'page_size' => $pageSize,
    ]);
    assert($storage instanceof LwsStorageInterface);
    $violations = $storage->validate();
    if ($violations->count() > 0) {
      $messages = [];
      foreach ($violations as $violation) {
        $messages[] = $violation->getPropertyPath() . ': ' . strip_tags((string) $violation->getMessage());
      }
      throw new \InvalidArgumentException(implode(' ', $messages));
    }

    $transaction = $this->database->startTransaction();
    try {
      $storage->save();
      $this->entityTypeManager->getStorage('lws_resource')->create([
        'storage' => $storage->id(),
        'name' => 'root/',
      ])->save();
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    return $storage;
  }

  /**
   * Creates an empty container with an exact name.
   *
   * @param \Drupal\lws_storage\Entity\LwsResourceInterface $parent
   *   The container to create it in.
   * @param string $name
   *   Its decoded name, without the trailing slash.
   *
   * @throws \InvalidArgumentException
   *   When the parent is not a container or the name cannot be used.
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   When the parent already has a member with that name.
   */
  public function createContainer(LwsResourceInterface $parent, string $name): LwsResourceInterface {
    if (!$parent->isContainer()) {
      throw new \InvalidArgumentException('Resources can only be created in a container.');
    }
    $error = ResourceName::creationError($name);
    if ($error !== NULL) {
      throw new \InvalidArgumentException($error);
    }
    return $this->insert($parent, $name . '/', $this->uuid->generate());
  }

  /**
   * Creates a resource in a container, named after an identity hint.
   *
   * The hint's name is used if it is free; otherwise a number is added, and
   * if two creates race for a name, the loser takes the next one.
   *
   * @param \Drupal\lws_storage\Entity\LwsResourceInterface $parent
   *   The container.
   * @param string|null $hint
   *   The identity hint, as the Slug header gave it.
   * @param bool $container
   *   Whether to create a container rather than a data resource.
   * @param resource|null $body
   *   For a data resource, its content as a readable stream.
   * @param string $mediaType
   *   For a data resource, the media type of its content.
   * @param \Drupal\lws\Agent\RequestingAgent|null $agent
   *   The creating agent, recorded for audit.
   * @param \Drupal\lws_storage\Linkset\UserMetadata|null $metadata
   *   The links clients manage, from the request's Link headers.
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   404 if the container was deleted meanwhile, 413 for a body too large,
   *   422 for content a file validator rejects, 507 beyond the quota.
   */
  public function createResource(LwsResourceInterface $parent, ?string $hint, bool $container, $body = NULL, string $mediaType = 'application/octet-stream', ?RequestingAgent $agent = NULL, ?UserMetadata $metadata = NULL): LwsResourceInterface {
    $uuid = $this->uuid->generate();
    $content = NULL;
    if (!$container) {
      $content = $this->content->write($body ?? throw new \InvalidArgumentException('A data resource needs a body.'), $this->storageUuid($parent), $uuid);
    }
    try {
      if ($content !== NULL) {
        $this->validate($content, $mediaType);
      }
      foreach (ResourceNames::candidates($hint, $container, $this->uuid->generate(...)) as $name) {
        if ($this->resources->findChild($parent, $name) !== NULL) {
          continue;
        }
        try {
          return $this->insert($parent, $name, $uuid, $content, $mediaType, $agent, $metadata);
        }
        catch (EntityStorageException $e) {
          // A concurrent create took the name; try the next one.
          if (!$e->getPrevious() instanceof IntegrityConstraintViolationException) {
            throw $e;
          }
        }
      }
      throw LwsHttpException::conflict('No name is free for the new resource.');
    }
    catch (\Throwable $e) {
      if ($content !== NULL) {
        $this->content->delete($content->uri);
      }
      throw $e;
    }
  }

  /**
   * Replaces the content of a data resource.
   *
   * @param \Drupal\lws_storage\Entity\LwsResourceInterface $resource
   *   The data resource.
   * @param resource $body
   *   The new content, as a readable stream.
   * @param string|null $mediaType
   *   Its media type; NULL keeps the current one.
   * @param callable(\Drupal\lws_storage\Entity\LwsResourceInterface): void|null $precondition
   *   Checks the request's preconditions against the resource as it is once
   *   locked, and throws to refuse.
   * @param \Drupal\lws_storage\Linkset\UserMetadata|null $metadata
   *   With "Prefer: set-linkset", the links clients manage that replace the
   *   current ones, in the same transaction.
   *
   * @return \Drupal\lws_storage\Entity\LwsResourceInterface
   *   The resource with its new content.
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   404 if it was deleted meanwhile, 413, 422 or 507 as for creates, and
   *   whatever the precondition throws.
   */
  public function replaceContent(LwsResourceInterface $resource, $body, ?string $mediaType, ?callable $precondition = NULL, ?UserMetadata $metadata = NULL): LwsResourceInterface {
    if ($resource->isContainer()) {
      throw new \InvalidArgumentException('Only data resources have content.');
    }
    $content = $this->content->write($body, $this->storageUuid($resource), (string) $resource->uuid());
    $mediaType ??= $resource->getMediaType() ?? 'application/octet-stream';
    try {
      // Validators may take a while, so they run before anything is locked.
      $this->validate($content, $mediaType);
    }
    catch (\Throwable $e) {
      $this->content->delete($content->uri);
      throw $e;
    }
    $transaction = $this->database->startTransaction();
    try {
      $current = $this->lock($resource);
      if ($precondition !== NULL) {
        $precondition($current);
      }
      $this->swap($current, $content, $mediaType, $metadata);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      $this->forget($resource, $resource->getParent());
      $this->content->delete($content->uri);
      throw $e;
    }
    return $current;
  }

  /**
   * Changes the content of a data resource, as it is once locked.
   *
   * For PATCH: the change is computed from the current content inside the
   * transaction, so that no concurrent write can be lost.
   *
   * @param \Drupal\lws_storage\Entity\LwsResourceInterface $resource
   *   The data resource.
   * @param \Closure(string): string $change
   *   Makes the new content from the current; throws to refuse.
   * @param callable(\Drupal\lws_storage\Entity\LwsResourceInterface): void|null $precondition
   *   Checks the request's preconditions against the locked resource.
   * @param \Drupal\lws_storage\Linkset\UserMetadata|null $metadata
   *   With "Prefer: set-linkset", the links clients manage to add, in the
   *   same transaction.
   *
   * @return \Drupal\lws_storage\Entity\LwsResourceInterface
   *   The resource with its new content.
   */
  public function changeContent(LwsResourceInterface $resource, \Closure $change, ?callable $precondition = NULL, ?UserMetadata $metadata = NULL): LwsResourceInterface {
    if ($resource->isContainer()) {
      throw new \InvalidArgumentException('Only data resources have content.');
    }
    $content = NULL;
    $transaction = $this->database->startTransaction();
    try {
      $current = $this->lock($resource);
      if ($precondition !== NULL) {
        $precondition($current);
      }
      $uri = $current->getContentFile()?->getFileUri();
      $bytes = $uri === NULL ? FALSE : @file_get_contents($uri);
      if ($bytes === FALSE) {
        throw new \RuntimeException(sprintf('The content of resource %s cannot be read.', $current->uuid()));
      }
      $stream = fopen('php://temp', 'w+b');
      if ($stream === FALSE) {
        throw new \RuntimeException('No temporary stream.');
      }
      fwrite($stream, $change($bytes));
      rewind($stream);
      $content = $this->content->write($stream, $this->storageUuid($current), (string) $current->uuid());
      $mediaType = $current->getMediaType() ?? 'application/octet-stream';
      $this->validate($content, $mediaType);
      $this->swap($current, $content, $mediaType, $metadata === NULL ? NULL : $current->getUserMetadata()->with($metadata));
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      $this->forget($resource, $resource->getParent());
      if ($content !== NULL) {
        $this->content->delete($content->uri);
      }
      throw $e;
    }
    return $current;
  }

  /**
   * Changes the links clients manage of a resource, as it is once locked.
   *
   * @param \Drupal\lws_storage\Entity\LwsResourceInterface $resource
   *   The resource.
   * @param \Closure(\Drupal\lws_storage\Entity\LwsResourceInterface): \Drupal\lws_storage\Linkset\UserMetadata $change
   *   Makes the new links from the locked resource; throws to refuse.
   * @param callable(\Drupal\lws_storage\Entity\LwsResourceInterface): void|null $precondition
   *   Checks the request's preconditions against the locked resource.
   *
   * @return \Drupal\lws_storage\Entity\LwsResourceInterface
   *   The resource with its new links.
   */
  public function changeMetadata(LwsResourceInterface $resource, \Closure $change, ?callable $precondition = NULL): LwsResourceInterface {
    $transaction = $this->database->startTransaction();
    try {
      $current = $this->lock($resource);
      if ($precondition !== NULL) {
        $precondition($current);
      }
      $current->setUserMetadata($change($current), $this->time->getRequestTime());
      $current->save();
      // Listings show members' types.
      $parent = $current->getParent();
      if ($parent !== NULL) {
        $this->resources->touch($parent);
      }
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      $this->forget($resource, $resource->getParent());
      throw $e;
    }
    return $current;
  }

  /**
   * Points a locked data resource at new content, inside the transaction.
   */
  private function swap(LwsResourceInterface $current, StoredContent $content, string $mediaType, ?UserMetadata $metadata): void {
    $previous = $current->getContentFile();
    $this->charge($current->getLwsStorageId(), $content->size - ($previous?->getSize() ?? 0));
    $file = $this->newFile($content, $current->getName(), $mediaType);
    $current->set('content', $file->id());
    $current->set('content_sha256', $content->sha256);
    $current->set('version', $current->getVersion() + 1);
    if ($metadata !== NULL) {
      $current->setUserMetadata($metadata, $this->time->getRequestTime());
    }
    $current->save();
    // Listings show a member's size, format, types and modification time.
    $parent = $current->getParent();
    if ($parent !== NULL) {
      $this->resources->touch($parent);
    }
    if ($previous !== NULL) {
      $fid = (int) $previous->id();
      $this->database->transactionManager()->addPostTransactionCallback(fn () => $this->releaseFile($fid));
    }
  }

  /**
   * Deletes a resource, and with recursion a container and all it holds.
   *
   * @param \Drupal\lws_storage\Entity\LwsResourceInterface $resource
   *   The resource; never the storage root.
   * @param bool $recursive
   *   Whether a container that is not empty may be deleted with its members.
   * @param callable(\Drupal\lws_storage\Entity\LwsResourceInterface): void|null $precondition
   *   Checks the request's preconditions against the resource as it is once
   *   locked, and throws to refuse.
   * @param callable(\Drupal\lws_storage\Entity\LwsResourceInterface): bool|null $mayDelete
   *   Whether the agent may delete a member; a recursive delete removes
   *   nothing unless it may delete every one.
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   404 if it was deleted meanwhile, 409 for a container that is not empty,
   *   422 for more members than a recursive delete may remove.
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   When the agent may not delete a member.
   */
  public function deleteResource(LwsResourceInterface $resource, bool $recursive = FALSE, ?callable $precondition = NULL, ?callable $mayDelete = NULL): void {
    if ($resource->isRoot()) {
      throw new \InvalidArgumentException('The storage root cannot be deleted.');
    }
    $transaction = $this->database->startTransaction();
    try {
      $current = $this->lock($resource);
      if ($precondition !== NULL) {
        $precondition($current);
      }
      $members = $current->isContainer() ? $this->resources->descendants($current, TRUE) : [];
      if ($members !== [] && !$recursive) {
        throw LwsHttpException::conflict('The container is not empty. Send "Depth: infinity" to delete it with its members.');
      }
      $limit = (int) $this->configFactory->get('lws_storage.settings')->get('max_recursive_delete');
      if ($limit > 0 && count($members) > $limit) {
        throw LwsHttpException::unprocessable(sprintf('The container holds %d resources, more than one request may delete (%d).', count($members), $limit));
      }
      if ($mayDelete !== NULL) {
        foreach ($members as $member) {
          if (!$mayDelete($member)) {
            throw new AccessDeniedHttpException('The agent may not delete every member of the container.');
          }
        }
      }

      $parent = $current->getParent();
      $deleted = [...$members, $current];
      $fids = [];
      $bytes = 0;
      foreach ($deleted as $item) {
        $file = $item->getContentFile();
        if ($file !== NULL) {
          $fids[] = (int) $file->id();
          $bytes += (int) $file->getSize();
        }
      }
      foreach (array_chunk($deleted, 100) as $chunk) {
        $this->entityTypeManager->getStorage('lws_resource')->delete($chunk);
      }
      if ($parent !== NULL) {
        $this->resources->touch($parent);
      }
      $this->charge($current->getLwsStorageId(), -$bytes);
      $this->database->transactionManager()->addPostTransactionCallback(function () use ($fids, $recursive): void {
        foreach ($fids as $fid) {
          // Recursive deletes leave their files to the queue, so the request
          // does not wait for every file to be deleted.
          $recursive ? $this->queueFactory->get(self::GC_QUEUE)->createItem(['fid' => $fid]) : $this->releaseFile($fid);
        }
      });
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      $this->forget($resource, $resource->getParent());
      throw $e;
    }
  }

  /**
   * Deletes a file of a former version, unless something still uses it.
   *
   * Something else, such as a Media item an administrator made from it, may
   * still use it; then it stays.
   */
  public function releaseFile(int $fid): void {
    $file = $this->entityTypeManager->getStorage('file')->load($fid);
    if ($file instanceof FileInterface && $this->content->owns((string) $file->getFileUri()) && $this->fileUsage->listUsage($file) === []) {
      $file->delete();
    }
  }

  /**
   * Inserts a resource with a name, in one transaction.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   With an integrity constraint violation when the name is taken.
   */
  private function insert(LwsResourceInterface $parent, string $name, string $uuid, ?StoredContent $content = NULL, string $mediaType = 'application/octet-stream', ?RequestingAgent $agent = NULL, ?UserMetadata $metadata = NULL): LwsResourceInterface {
    $transaction = $this->database->startTransaction();
    try {
      // Update the parent first, which locks it: if a recursive delete
      // removed it meanwhile, nothing is updated and the create fails rather
      // than leave an orphan.
      if (!$this->resources->touch($parent)) {
        throw LwsHttpException::notFound('The container no longer exists.');
      }
      $values = [
        'uuid' => $uuid,
        'storage' => $parent->getLwsStorageId(),
        'parent' => $parent->id(),
        'name' => $name,
        'creator' => $agent?->subject,
        'creator_client' => $agent?->client,
      ];
      if ($content !== NULL) {
        $this->charge($parent->getLwsStorageId(), $content->size);
        $values['content'] = $this->newFile($content, $name, $mediaType)->id();
        $values['content_sha256'] = $content->sha256;
      }
      $resource = $this->entityTypeManager->getStorage('lws_resource')->create($values);
      assert($resource instanceof LwsResourceInterface);
      if ($metadata !== NULL && !$metadata->isEmpty()) {
        $resource->setUserMetadata($metadata, $this->time->getRequestTime());
      }
      $resource->save();
      return $resource;
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      $this->forget($parent);
      throw $e;
    }
  }

  /**
   * Drops cached copies of resources after a rollback.
   *
   * Entities loaded inside a transaction that was rolled back may hold its
   * changes, such as a container's version.
   */
  private function forget(?LwsResourceInterface ...$resources): void {
    $ids = [];
    foreach ($resources as $resource) {
      if ($resource !== NULL) {
        $ids[] = (int) $resource->id();
      }
    }
    $this->entityTypeManager->getStorage('lws_resource')->resetCache($ids);
  }

  /**
   * Locks a resource's row and loads it as it is now.
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   404 if it no longer exists.
   */
  private function lock(LwsResourceInterface $resource): LwsResourceInterface {
    $query = $this->database->select('lws_resource', 'r')
      ->fields('r', ['id'])
      ->condition('id', $resource->id());
    $query->forUpdate();
    $locked = $query->execute()?->fetchField();
    $current = $locked === FALSE || $locked === NULL ? NULL : $this->entityTypeManager->getStorage('lws_resource')->loadUnchanged((int) $resource->id());
    if (!$current instanceof LwsResourceInterface) {
      throw LwsHttpException::notFound();
    }
    return $current;
  }

  /**
   * Adds bytes to a storage's usage, within its quota.
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   507 when an increase would exceed the quota.
   */
  private function charge(int $storageId, int $delta): void {
    if ($delta === 0) {
      return;
    }
    $query = $this->database->update('lws_storage')->condition('id', $storageId);
    if ($delta > 0) {
      $query->expression('used_bytes', '[used_bytes] + :delta', [':delta' => $delta])
        ->where('[quota_bytes] IS NULL OR [used_bytes] + :room <= [quota_bytes]', [':room' => $delta]);
    }
    else {
      $query->expression('used_bytes', 'CASE WHEN [used_bytes] < :freed THEN 0 ELSE [used_bytes] - :freed END', [':freed' => -$delta]);
    }
    if ($query->execute() === 0 && $delta > 0) {
      throw LwsHttpException::insufficientStorage();
    }
    $this->entityTypeManager->getStorage('lws_storage')->resetCache([$storageId]);
  }

  /**
   * Saves a permanent file entity for stored content.
   */
  private function newFile(StoredContent $content, string $name, string $mediaType): FileInterface {
    $file = $this->entityTypeManager->getStorage('file')->create([
      'uri' => $content->uri,
      'filename' => rtrim($name, '/'),
      'filemime' => $mediaType,
      'filesize' => $content->size,
      'uid' => 0,
    ]);
    $file->setPermanent();
    $file->save();
    return $file;
  }

  /**
   * Runs core's file validation, and the validators modules attach to it.
   *
   * The file is checked under its stored name, made of UUIDs, rather than
   * the resource name: core always refuses names such as "app.js" as
   * insecure uploads, which matters for files served from the public file
   * system, not for LWS content.
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   422 when a validator rejects the content.
   */
  private function validate(StoredContent $content, string $mediaType): void {
    $file = $this->entityTypeManager->getStorage('file')->create([
      'uri' => $content->uri,
      'filename' => basename($content->uri),
      'filemime' => $mediaType,
      'filesize' => $content->size,
    ]);
    $messages = [];
    foreach ($this->fileValidator->validate($file, []) as $violation) {
      $messages[] = strip_tags((string) $violation->getMessage());
    }
    if ($messages !== []) {
      throw LwsHttpException::unprocessable(implode(' ', $messages));
    }
  }

  /**
   * The UUID of the storage a resource belongs to.
   */
  private function storageUuid(LwsResourceInterface $resource): string {
    $storage = $this->entityTypeManager->getStorage('lws_storage')->load($resource->getLwsStorageId());
    return (string) $storage?->uuid();
  }

}
