<?php

declare(strict_types=1);

namespace Drupal\lws_storage;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\Transaction;
use Drupal\Core\DestructableInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\file\Validation\FileValidatorInterface;
use Drupal\lws\Agent\RequestingAgent;
use Drupal\lws\Database\TransactionConflict;
use Drupal\lws\Http\LwsHttpException;
use Drupal\lws\Routing\ResourceName;
use Drupal\lws_authz\Server\LocalAuthorizationServer;
use Drupal\lws_storage\Content\ContentStore;
use Drupal\lws_storage\Content\StoredContent;
use Drupal\lws_storage\Entity\LwsResourceInterface;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\Linkset\UserMetadata;
use Symfony\Component\EventDispatcher\EventDispatcherInterface as SymfonyEventDispatcherInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Creates, replaces and deletes storages and resources.
 *
 * Each operation is one database transaction (LWS Core §7.3). Content is
 * written to a new file before the transaction, and the transaction only
 * moves the resource's reference to it (DESIGN.md §5.3): readers never see a
 * half-written resource, and a failed transaction leaves the old content in
 * place and deletes the new bytes.
 *
 * Each change to a resource is announced with an LwsResourceEvent once its
 * transaction commits: right after it, or when the request ends if an outer
 * transaction committed it.
 *
 * Every transaction locks rows in one order: down the tree, a container
 * before its members, and the storage's own row, for its quota, last. Two
 * operations that need the same rows then wait for each other in turn,
 * rather than each holding what the other needs. When the database still
 * gives up on a transaction because of a concurrent one, on a deadlock or a
 * lock it waited too long for, the operation runs again (transactional()).
 */
final class StorageManager implements DestructableInterface {

  /**
   * The queue of files to delete once nothing uses them.
   */
  public const GC_QUEUE = 'lws_storage_gc';

  /**
   * How often an operation is tried while concurrent ones make it fail.
   */
  private const ATTEMPTS = 3;

  /**
   * The largest content a change computed from the current one may read.
   */
  public const MAX_CHANGE_BYTES = 16777216;

  /**
   * Events of committed changes, waiting to be dispatched.
   *
   * @var list<array{string, \Drupal\lws_storage\LwsResourceEvent}>
   */
  private array $committed = [];

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
    private readonly EventDispatcherInterface $events,
    private readonly ResourceLinks $links,
    private readonly AccountInterface $currentUser,
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

    // Saving makes the root container too (LwsStorage::postSave()).
    $storage->save();
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
    if ($this->resources->findChild($parent, $name) !== NULL) {
      throw new \InvalidArgumentException(sprintf('The container already has a data resource named %s.', $name));
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
   * @param resource|\Drupal\lws\Http\RequestBody|null $body
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
      $content = $this->content->write(
        $body ?? throw new \InvalidArgumentException('A data resource needs a body.'),
        $this->storageUuid($parent),
        $uuid,
        $this->room($parent->getLwsStorageId()),
      );
    }
    try {
      if ($content !== NULL) {
        $this->validate($content, $mediaType);
      }
      foreach (ResourceNames::candidates($hint, $container, $this->uuid->generate(...)) as $name) {
        // A name is taken by its twin with or without the slash too: "notes"
        // and "notes/" side by side would read as one resource to people.
        $twin = str_ends_with($name, '/') ? substr($name, 0, -1) : $name . '/';
        if ($this->resources->findChild($parent, $name) !== NULL || $this->resources->findChild($parent, $twin) !== NULL) {
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
   * @param resource|\Drupal\lws\Http\RequestBody $body
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
    $content = $this->content->write($body, $this->storageUuid($resource), (string) $resource->uuid(), $this->room($resource->getLwsStorageId(), (int) $resource->getSize()));
    $mediaType ??= $resource->getMediaType() ?? 'application/octet-stream';
    try {
      // Validators may take a while, so they run before anything is locked.
      $this->validate($content, $mediaType);
    }
    catch (\Throwable $e) {
      $this->content->delete($content->uri);
      throw $e;
    }
    try {
      return $this->transactional(
        function () use ($resource, $content, $mediaType, $precondition, $metadata): LwsResourceInterface {
          $current = $this->lock($resource);
          if ($precondition !== NULL) {
            $precondition($current);
          }
          $this->swap($current, $content, $mediaType, $metadata);
          return $current;
        },
        fn () => $this->forget($resource, $resource->getParent()),
      );
    }
    catch (\Throwable $e) {
      // The new bytes serve every attempt, and go only once none succeeded.
      $this->content->delete($content->uri);
      throw $e;
    }
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
    // Each attempt makes its content from the content it finds.
    /** @var \Drupal\lws_storage\Content\StoredContent|null $content */
    $content = NULL;
    return $this->transactional(
      function () use ($resource, $change, $precondition, $metadata, &$content): LwsResourceInterface {
        $current = $this->lock($resource);
        if ($precondition !== NULL) {
          $precondition($current);
        }
        if (($current->getSize() ?? 0) > self::MAX_CHANGE_BYTES) {
          throw LwsHttpException::unprocessable('The resource is too large to patch; replace it with PUT.');
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
        $content = $this->content->write($stream, $this->storageUuid($current), (string) $current->uuid(), $this->room($current->getLwsStorageId(), (int) $current->getSize()));
        $mediaType = $current->getMediaType() ?? 'application/octet-stream';
        $this->validate($content, $mediaType);
        $this->swap($current, $content, $mediaType, $metadata === NULL ? NULL : $current->getUserMetadata()->with($metadata));
        return $current;
      },
      function () use ($resource, &$content): void {
        $this->forget($resource, $resource->getParent());
        if ($content !== NULL) {
          $this->content->delete($content->uri);
          $content = NULL;
        }
      },
    );
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
    return $this->transactional(
      function () use ($resource, $change, $precondition): LwsResourceInterface {
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
        $this->announce(LwsResourceEvent::METADATA_UPDATED, [$current]);
        return $current;
      },
      fn () => $this->forget($resource, $resource->getParent()),
    );
  }

  /**
   * Points a locked data resource at new content, inside the transaction.
   *
   * Its container is locked already (lock()), and the storage's row, for the
   * quota, is locked last.
   */
  private function swap(LwsResourceInterface $current, StoredContent $content, string $mediaType, ?UserMetadata $metadata): void {
    $previous = $current->getContentFile();
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
      // Only once the resource no longer refers to it: rolled back, it does.
      $this->database->transactionManager()->addPostTransactionCallback(function (bool $committed) use ($fid): void {
        if ($committed) {
          $this->releaseFile($fid);
        }
      });
    }
    $this->announce(LwsResourceEvent::UPDATED, [$current]);
    $this->charge($current->getLwsStorageId(), $content->size - ($previous?->getSize() ?? 0));
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
    $this->transactional(
      function () use ($resource, $recursive, $precondition, $mayDelete): void {
        $current = $this->lock($resource);
        if ($precondition !== NULL) {
          $precondition($current);
        }
        $members = [];
        if ($current->isContainer() && $this->resources->hasMembers($current)) {
          if (!$recursive) {
            throw LwsHttpException::conflict('The container is not empty. Send "Depth: infinity" to delete it with its members.');
          }
          // Counted before anything is loaded or locked, and checked again
          // once it is. The count is not told: the agent may not see them all.
          $limit = (int) $this->configFactory->get('lws_storage.settings')->get('max_recursive_delete');
          $tooMany = LwsHttpException::unprocessable(sprintf('The container holds more resources than one request may delete (%d). Delete some of its members first.', $limit));
          if ($limit > 0 && $this->resources->countDescendants($current, $limit + 1) > $limit) {
            throw $tooMany;
          }
          $members = $this->resources->descendants($current, TRUE);
          if ($limit > 0 && count($members) > $limit) {
            throw $tooMany;
          }
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
        // Described while they still exist.
        $this->announce(LwsResourceEvent::DELETED, $deleted);
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
        $this->database->transactionManager()->addPostTransactionCallback(function (bool $committed) use ($fids, $recursive): void {
          // Rolled back, the resources still refer to their files.
          if (!$committed) {
            return;
          }
          foreach ($fids as $fid) {
            // Recursive deletes leave their files to the queue, so the request
            // does not wait for every file to be deleted.
            $recursive ? $this->queueFactory->get(self::GC_QUEUE)->createItem(['fid' => $fid]) : $this->releaseFile($fid);
          }
        });
      },
      fn () => $this->forget($resource, $resource->getParent()),
    );
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
    if (mb_strlen($parent->getPath() . $name) > LwsResourceInterface::MAX_PATH_LENGTH) {
      throw LwsHttpException::badRequest(sprintf('The resource would be nested too deeply: its path would be longer than %d characters.', LwsResourceInterface::MAX_PATH_LENGTH));
    }
    return $this->transactional(
      function () use ($parent, $name, $uuid, $content, $mediaType, $agent, $metadata): LwsResourceInterface {
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
          $values['content'] = $this->newFile($content, $name, $mediaType)->id();
          $values['content_sha256'] = $content->sha256;
        }
        $resource = $this->entityTypeManager->getStorage('lws_resource')->create($values);
        assert($resource instanceof LwsResourceInterface);
        if ($metadata !== NULL && !$metadata->isEmpty()) {
          $resource->setUserMetadata($metadata, $this->time->getRequestTime());
        }
        $resource->save();
        $this->announce(LwsResourceEvent::CREATED, [$resource]);
        if ($content !== NULL) {
          // The storage's row is locked last.
          $this->charge($parent->getLwsStorageId(), $content->size);
        }
        return $resource;
      },
      fn () => $this->forget($parent),
    );
  }

  /**
   * Dispatches the events of committed changes.
   *
   * Never from the commit itself: a transaction commits in its destructor,
   * where PHP before 8.4 lets no fiber switch, and loading an entity inside a
   * fiber switches fibers.
   */
  public function dispatchCommitted(): void {
    while ($this->committed !== []) {
      $events = $this->committed;
      $this->committed = [];
      foreach ($events as [$name, $event]) {
        $this->events->dispatch($event, $name);
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * Dispatches the events of changes an outer transaction committed.
   */
  public function destruct(): void {
    $this->dispatchCommitted();
  }

  /**
   * Runs an operation's database work in a transaction.
   *
   * When the database gives up on the transaction because of a concurrent
   * one, a deadlock or a lock it waited too long for, the work runs again in
   * a new one, up to ATTEMPTS times in all; then the conflict is thrown, which
   * the LWS URL space answers with 503 (LwsExceptionSubscriber). Inside an
   * outer transaction the work runs once: the database rolled back all of the
   * outer one, which only its owner can try again.
   *
   * @param \Closure(): T $work
   *   The work. It may run more than once, so it reads what it needs anew.
   * @param \Closure(): void $rolledBack
   *   Undoes what an attempt did outside the database, such as caching or
   *   writing content, after its transaction rolled back.
   *
   * @return T
   *   What the work returns.
   *
   * @template T
   */
  private function transactional(\Closure $work, \Closure $rolledBack): mixed {
    $attempts = $this->database->inTransaction() ? 1 : self::ATTEMPTS;
    for ($attempt = 1;; $attempt++) {
      $transaction = $this->database->startTransaction();
      try {
        $result = $work();
      }
      catch (\Throwable $e) {
        $transaction->rollBack();
        // Released before the next attempt starts its transaction: core ends
        // a rolled-back root transaction, and runs its post-transaction
        // callbacks, only when the object goes.
        unset($transaction);
        $rolledBack();
        if ($attempt < $attempts && TransactionConflict::is($e)) {
          // A short, random wait, so that two that collided do not collide
          // again at once.
          usleep(random_int(5000, 25000) * $attempt);
          continue;
        }
        throw $e;
      }
      $this->commit($transaction);
      return $result;
    }
  }

  /**
   * Ends an operation's transaction, and announces what it committed.
   *
   * When it is the outermost transaction, it commits here; otherwise its
   * changes are announced once the outermost one commits.
   *
   * @param \Drupal\Core\Database\Transaction|null $transaction
   *   The operation's transaction, which is released.
   *
   * @param-out null $transaction
   */
  private function commit(?Transaction &$transaction): void {
    $transaction = NULL;
    $this->dispatchCommitted();
  }

  /**
   * Announces changes to resources once the transaction commits.
   *
   * Called inside the transaction that makes the changes. The events are
   * made now, while deleted resources still exist, and dispatched only if
   * the transaction commits.
   *
   * @param string $name
   *   The event: an LwsResourceEvent constant.
   * @param list<\Drupal\lws_storage\Entity\LwsResourceInterface> $resources
   *   The resources, all of one storage.
   */
  private function announce(string $name, array $resources): void {
    if ($resources === [] || ($this->events instanceof SymfonyEventDispatcherInterface && !$this->events->hasListeners($name))) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage('lws_storage')->load($resources[0]->getLwsStorageId());
    if (!$storage instanceof LwsStorageInterface) {
      return;
    }
    $time = $this->time->getCurrentMicroTime();
    $events = [];
    foreach ($this->links->contextsOf($storage, $resources) as $i => $context) {
      $events[] = new LwsResourceEvent($context, (int) $resources[$i]->id(), (string) $resources[$i]->uuid(), $time);
    }
    $this->database->transactionManager()->addPostTransactionCallback(function (bool $committed) use ($name, $events): void {
      if ($committed) {
        foreach ($events as $event) {
          $this->committed[] = [$name, $event];
        }
      }
    });
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
   * Locks a resource's row, after its container's, and loads it as it is now.
   *
   * The container is locked first, as a create in it locks it (insert()), so
   * that a change and a create in one container do not each hold a row the
   * other waits for.
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   404 if it no longer exists.
   */
  private function lock(LwsResourceInterface $resource): LwsResourceInterface {
    $parent = (int) $resource->get('parent')->target_id;
    if ($parent > 0) {
      $this->lockRow($parent);
    }
    $current = $this->lockRow((int) $resource->id()) ? $this->entityTypeManager->getStorage('lws_resource')->loadUnchanged((int) $resource->id()) : NULL;
    if (!$current instanceof LwsResourceInterface) {
      throw LwsHttpException::notFound();
    }
    return $current;
  }

  /**
   * Locks a resource's row until the transaction ends.
   *
   * @return bool
   *   FALSE if there is no such row.
   */
  private function lockRow(int $id): bool {
    $query = $this->database->select('lws_resource', 'r')
      ->fields('r', ['id'])
      ->condition('id', $id);
    $query->forUpdate();
    $locked = $query->execute()?->fetchField();
    return $locked !== FALSE && $locked !== NULL;
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
   * The bytes a storage's quota leaves room for; NULL for no quota.
   *
   * @param int $storageId
   *   The storage.
   * @param int $freed
   *   The bytes the write frees, such as those of the content it replaces.
   */
  private function room(int $storageId, int $freed = 0): ?int {
    $row = $this->database->select('lws_storage', 's')
      ->fields('s', ['quota_bytes', 'used_bytes'])
      ->condition('id', $storageId)
      ->execute()
      ?->fetchAssoc();
    if (!is_array($row) || $row['quota_bytes'] === NULL) {
      return NULL;
    }
    return max(0, (int) $row['quota_bytes'] - (int) $row['used_bytes'] + $freed);
  }

  /**
   * Saves a permanent file entity for stored content.
   *
   * It is the current user's: anonymous for an agent, unless lws_agent_users
   * makes the agent a user; the user for administration pages and Drush.
   */
  private function newFile(StoredContent $content, string $name, string $mediaType): FileInterface {
    $file = $this->entityTypeManager->getStorage('file')->create([
      'uri' => $content->uri,
      'filename' => rtrim($name, '/'),
      'filemime' => $mediaType,
      'filesize' => $content->size,
      'uid' => (int) $this->currentUser->id(),
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
