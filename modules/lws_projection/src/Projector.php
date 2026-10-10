<?php

declare(strict_types=1);

namespace Drupal\lws_projection;

use Drupal\Core\DestructableInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws\Storage\StorageRegistryInterface;
use Drupal\lws_authz\Policy\AccessPolicy;
use Drupal\lws_authz\Policy\AccessPolicyParser;
use Drupal\lws_authz\Policy\PolicyStore;
use Drupal\lws_projection\Entity\ProjectionInterface;
use Drupal\lws_storage\Entity\LwsResourceInterface;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\Linkset\UserMetadata;
use Drupal\lws_storage\ResourceRepository;
use Drupal\lws_storage\StorageManager;
use Ebremer\Lws\ResourceType;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Projects Drupal content into storages, and keeps them up to date.
 *
 * DESIGN.md §7.4. An entity is projected as an anonymous visitor sees it:
 * only if a visitor may view it, serialized by core's serializer as core's
 * REST module serves it ("json"), with only the fields a visitor may view.
 * Its resource, root/{entity type}/{bundle}/{ID}, is application/json,
 * declares the bundle's type if the projection names one, and links the
 * entity's page as "alternate". Only what changed is written, so entity
 * tags, modification times and notifications follow real changes.
 *
 * Entities saved or deleted are projected when the request ends, after the
 * response. A whole projection is synced in chunks through a queue, on cron,
 * or with Drush: when it is made or changed, and when the anonymous role
 * changes. The writes pass the access decision by, as projected storages
 * are read-only to every agent (ReadOnlyProjections), and their files are
 * Anonymous's.
 */
final class Projector implements EventSubscriberInterface, DestructableInterface {

  /**
   * The queue of projections to sync.
   */
  public const QUEUE = 'lws_projection_sync';

  /**
   * The media type of projected resources.
   */
  public const MEDIA_TYPE = 'application/json';

  /**
   * The source of the policy that lets anyone read a projected storage.
   */
  public const POLICY_SOURCE = 'lws_projection:';

  /**
   * The entities one step of a sync projects.
   */
  public const CHUNK = 100;

  /**
   * An entity ID that names a resource as it is.
   */
  private const NAME = '/^[A-Za-z0-9._~-]+$/';

  /**
   * Entities to project when the request ends.
   *
   * @var array<string, array{0: string, 1: int|string, 2: string|null}>
   *   Entity type, ID and, for one deleted, its bundle; by type and ID.
   */
  private array $pending = [];

  /**
   * The storages of projections, as looked up in this request.
   *
   * @var array<string, \Drupal\lws_storage\Entity\LwsStorageInterface|null>
   */
  private array $storages = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly StorageManager $manager,
    private readonly ResourceRepository $resources,
    private readonly StorageRegistryInterface $registry,
    private readonly Projections $projections,
    private readonly SerializerInterface $serializer,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly PolicyStore $policies,
    private readonly AccessPolicyParser $policyParser,
    private readonly LwsUrlGenerator $urls,
    private readonly QueueFactory $queueFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   *
   * @return array<string, array{string, int}>
   *   The events and their listeners.
   */
  public static function getSubscribedEvents(): array {
    // Before lws_notify's Notifier sends what the request changed (0).
    return [KernelEvents::TERMINATE => ['flush', 100]];
  }

  /**
   * Notes an entity made or changed, to project when the request ends.
   */
  public function changed(EntityInterface $entity): void {
    $this->note($entity, FALSE);
  }

  /**
   * Notes an entity deleted, to take out when the request ends.
   */
  public function deleted(EntityInterface $entity): void {
    $this->note($entity, TRUE);
  }

  /**
   * Notes an entity of a projected bundle, to deal with when the request ends.
   */
  private function note(EntityInterface $entity, bool $deleted): void {
    $id = $entity->id();
    if (!$entity instanceof ContentEntityInterface || $id === NULL) {
      return;
    }
    $type = $entity->getEntityTypeId();
    if ($this->projections->covering($type, $entity->bundle()) !== []) {
      $this->pending[$type . ':' . $id] = [$type, $id, $deleted ? $entity->bundle() : NULL];
    }
  }

  /**
   * Projects the entities this request changed.
   *
   * A failure is logged: the change itself is made, and a sync mends the
   * projection.
   */
  public function flush(): void {
    $pending = $this->pending;
    $this->pending = [];
    foreach ($pending as [$type, $id, $deletedBundle]) {
      try {
        if ($deletedBundle !== NULL) {
          foreach ($this->projections->covering($type, $deletedBundle) as $projection) {
            $this->remove($projection, $type, $deletedBundle, (string) $id);
          }
          continue;
        }
        $storage = $this->entityTypeManager->getStorage($type);
        $storage->resetCache([$id]);
        $entity = $storage->load($id);
        if ($entity instanceof ContentEntityInterface) {
          foreach ($this->projections->covering($type, $entity->bundle()) as $projection) {
            $this->project($projection, $entity);
          }
        }
      }
      catch (\Throwable $e) {
        $this->logger->error('@type @id was not projected: @message. drush lws:projection:sync mends it.', [
          '@type' => $type,
          '@id' => $id,
          '@message' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * For changes made where no response is sent, such as with Drush.
   */
  public function destruct(): void {
    $this->flush();
  }

  /**
   * Queues a whole projection to sync on cron.
   */
  public function enqueue(ProjectionInterface $projection): void {
    $this->queueFactory->get(self::QUEUE)->createItem(['projection' => $projection->id(), 'cursor' => []]);
  }

  /**
   * Queues every projection to sync on cron.
   */
  public function enqueueAll(): void {
    foreach ($this->projections->all() as $projection) {
      $this->enqueue($projection);
    }
  }

  /**
   * Makes a projection's storage if it has none, and its public policy.
   *
   * @return \Drupal\lws_storage\Entity\LwsStorageInterface|null
   *   The storage; NULL if another storage has its slug.
   */
  public function prepare(ProjectionInterface $projection): ?LwsStorageInterface {
    unset($this->storages[(string) $projection->id()]);
    $storage = $this->storageOf($projection, TRUE);
    if ($storage !== NULL) {
      $this->syncPolicy($projection, $storage);
    }
    return $storage;
  }

  /**
   * Syncs a whole projection.
   */
  public function sync(ProjectionInterface $projection): void {
    $cursor = [];
    do {
      $cursor = $this->syncFrom($projection, $cursor);
    } while ($cursor !== NULL);
  }

  /**
   * Syncs one step of a projection.
   *
   * The first step makes the storage if need be; each projects up to a chunk
   * of entities of one bundle; the last takes out what no longer belongs.
   *
   * @param \Drupal\lws_projection\Entity\ProjectionInterface $projection
   *   The projection.
   * @param array{bundle?: int, after?: int|string} $cursor
   *   Where the step starts: [] for the first.
   * @param int $limit
   *   The most entities to project.
   *
   * @return array{bundle: int, after?: int|string}|null
   *   Where the next step starts; NULL when the sync is done.
   */
  public function syncFrom(ProjectionInterface $projection, array $cursor = [], int $limit = self::CHUNK): ?array {
    if ($cursor === [] && $this->prepare($projection) === NULL) {
      return NULL;
    }
    $index = (int) ($cursor['bundle'] ?? 0);
    $bundles = $projection->getBundles();
    if (!isset($bundles[$index])) {
      $this->prune($projection);
      return NULL;
    }
    ['entity_type' => $type, 'bundle' => $bundle] = $bundles[$index];
    $definition = $this->entityTypeManager->getDefinition($type, FALSE);
    if ($definition === NULL) {
      return ['bundle' => $index + 1];
    }
    $storage = $this->entityTypeManager->getStorage($type);
    $idKey = (string) $definition->getKey('id');
    $query = $storage->getQuery()->accessCheck(FALSE)->sort($idKey)->range(0, $limit);
    if ($definition->hasKey('bundle')) {
      $query->condition((string) $definition->getKey('bundle'), $bundle);
    }
    if (isset($cursor['after'])) {
      $query->condition($idKey, $cursor['after'], '>');
    }
    $ids = array_values($query->execute());
    foreach ($storage->loadMultiple($ids) as $entity) {
      if (!$entity instanceof ContentEntityInterface) {
        continue;
      }
      try {
        $this->project($projection, $entity);
      }
      catch (\Throwable $e) {
        $this->logger->error('@type @id was not projected: @message', [
          '@type' => $type,
          '@id' => (string) $entity->id(),
          '@message' => $e->getMessage(),
        ]);
      }
    }
    $storage->resetCache($ids);
    if ($ids === [] || count($ids) < $limit) {
      return ['bundle' => $index + 1];
    }
    return ['bundle' => $index, 'after' => $ids[array_key_last($ids)]];
  }

  /**
   * Projects an entity, or takes it out if a visitor may not see it.
   */
  public function project(ProjectionInterface $projection, ContentEntityInterface $entity): void {
    $storage = $this->storageOf($projection, TRUE);
    if ($storage === NULL) {
      return;
    }
    $entity = $entity->getUntranslated();
    $type = $entity->getEntityTypeId();
    $bundle = $entity->bundle();
    $name = (string) $entity->id();
    $existing = $this->resources->findByPath($storage, self::path($type, $bundle, $name));
    $anonymous = new AnonymousUserSession();
    // Access results are kept for the life of the process, which a sync or a
    // queue run outlasts.
    $this->entityTypeManager->getAccessControlHandler($type)->resetCache();
    // As a visitor, for what access and serialization ask of the current
    // user; the files written are then Anonymous's too.
    $this->accountSwitcher->switchTo($anonymous);
    try {
      if (preg_match(self::NAME, $name) !== 1 || !$entity->access('view', $anonymous)) {
        if ($existing !== NULL) {
          $this->manager->deleteResource($existing);
        }
        return;
      }
      $json = $this->serializer->serialize($entity, 'json', ['account' => $anonymous]);
      $metadata = $this->metadata($projection, $entity);
      if ($existing === NULL) {
        $created = $this->manager->createResource($this->container($storage, $type, $bundle), $name, FALSE, self::stream($json), self::MEDIA_TYPE, NULL, $metadata);
        // Another request projecting the same entity took the name first.
        if ($created->getName() !== $name) {
          $this->manager->deleteResource($created);
        }
        return;
      }
      $sameMetadata = self::sameMetadata($existing->getUserMetadata(), $metadata);
      if (self::content($existing) !== $json || $existing->getMediaType() !== self::MEDIA_TYPE) {
        $this->manager->replaceContent($existing, self::stream($json), self::MEDIA_TYPE, NULL, $sameMetadata ? NULL : $metadata);
      }
      elseif (!$sameMetadata) {
        $this->manager->changeMetadata($existing, static fn (): UserMetadata => $metadata);
      }
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
  }

  /**
   * Takes an entity out of a projection.
   */
  public function remove(ProjectionInterface $projection, string $type, string $bundle, string $id): void {
    $storage = $this->storageOf($projection, FALSE);
    $resource = $storage === NULL ? NULL : $this->resources->findByPath($storage, self::path($type, $bundle, $id));
    if ($resource !== NULL) {
      $this->manager->deleteResource($resource);
    }
  }

  /**
   * The path of an entity's resource in its storage.
   */
  public static function path(string $type, string $bundle, string $id): string {
    return 'root/' . $type . '/' . $bundle . '/' . $id;
  }

  /**
   * The storage of a projection.
   *
   * @param \Drupal\lws_projection\Entity\ProjectionInterface $projection
   *   The projection.
   * @param bool $make
   *   Whether to make it if the projection has none.
   *
   * @return \Drupal\lws_storage\Entity\LwsStorageInterface|null
   *   The storage; NULL if there is none, or another storage has the slug.
   */
  private function storageOf(ProjectionInterface $projection, bool $make): ?LwsStorageInterface {
    $key = (string) $projection->id();
    if (isset($this->storages[$key])) {
      return $this->storages[$key];
    }
    $ref = $this->registry->get($projection->getSlug());
    if ($ref !== NULL && $ref->id !== $this->projections->storageId($key)) {
      $this->logger->error('Projection @projection has no storage: another storage is named @slug.', [
        '@projection' => $key,
        '@slug' => $projection->getSlug(),
      ]);
      return NULL;
    }
    if ($ref === NULL) {
      if (!$make) {
        return NULL;
      }
      $made = $this->manager->createStorage($projection->getSlug(), (string) $projection->label());
      $this->projections->setStorageId($key, (int) $made->id());
      $this->syncPolicy($projection, $made);
      $this->logger->notice('Made storage @slug for projection @projection.', [
        '@slug' => $projection->getSlug(),
        '@projection' => $key,
      ]);
    }
    $storage = $this->entityTypeManager->getStorage('lws_storage')->loadByProperties(['slug' => $projection->getSlug()]);
    $storage = reset($storage);
    return $this->storages[$key] = $storage instanceof LwsStorageInterface ? $storage : NULL;
  }

  /**
   * Lets anyone read a projection's storage, or no longer, as it says.
   *
   * The policy is the projection's, by its source; others are the storage's
   * own, made on its access page or by its controllers.
   */
  private function syncPolicy(ProjectionInterface $projection, LwsStorageInterface $storage): void {
    $source = self::POLICY_SOURCE . $projection->id();
    $has = $this->policies->forSource($source) !== [];
    if ($projection->isPublic() && !$has) {
      $ref = $this->registry->get($storage->getSlug());
      if ($ref !== NULL) {
        $document = AccessPolicy::document(AccessPolicy::PUBLIC, ['read'], ResourceType::STORAGE_RESOURCE, [$ref->uri]);
        $this->policies->add($ref, $this->policyParser->parse($document, $ref), $source);
      }
    }
    elseif (!$projection->isPublic() && $has) {
      $this->policies->deleteBySource($source);
    }
  }

  /**
   * The container of a bundle, made along with its entity type's if need be.
   */
  private function container(LwsStorageInterface $storage, string ...$names): LwsResourceInterface {
    $parent = $this->resources->root($storage);
    $path = 'root/';
    foreach ($names as $name) {
      $path .= $name . '/';
      $child = $this->resources->findByPath($storage, $path);
      if ($child === NULL) {
        $child = $this->manager->createResource($parent, $name, TRUE);
        // Another request made it first; this one took the next name.
        if ($child->getPath() !== $path) {
          $this->manager->deleteResource($child);
          $child = $this->resources->findByPath($storage, $path) ?? throw new \RuntimeException(sprintf('The container %s is missing.', $path));
        }
      }
      $parent = $child;
    }
    return $parent;
  }

  /**
   * Takes out what no longer belongs in a projection's storage.
   *
   * That is the containers of entity types and bundles it no longer
   * projects, and the resources of entities that are gone.
   */
  private function prune(ProjectionInterface $projection): void {
    $storage = $this->storageOf($projection, FALSE);
    if ($storage === NULL) {
      return;
    }
    foreach ($this->resources->children($this->resources->root($storage)) as $typeContainer) {
      $type = rtrim($typeContainer->getName(), '/');
      if (!$typeContainer->isContainer() || !$projection->coversType($type)) {
        $this->deleteTree($typeContainer);
        continue;
      }
      foreach ($this->resources->children($typeContainer) as $bundleContainer) {
        $bundle = rtrim($bundleContainer->getName(), '/');
        if (!$bundleContainer->isContainer() || !$projection->covers($type, $bundle)) {
          $this->deleteTree($bundleContainer);
          continue;
        }
        $this->pruneMembers($bundleContainer, $type, $bundle);
      }
    }
  }

  /**
   * Takes out the resources of a bundle's entities that are gone.
   */
  private function pruneMembers(LwsResourceInterface $container, string $type, string $bundle): void {
    $definition = $this->entityTypeManager->getDefinition($type);
    $storage = $this->entityTypeManager->getStorage($type);
    $idKey = (string) $definition->getKey('id');
    $numeric = ($this->entityFieldManager->getBaseFieldDefinitions($type)[$idKey] ?? NULL)?->getType() === 'integer';
    $after = NULL;
    while (($members = $this->resources->membersAfter($container, $after, self::CHUNK)) !== []) {
      $after = end($members)->getName();
      $names = [];
      foreach ($members as $member) {
        $name = $member->getName();
        if (!$member->isContainer() && (!$numeric || ctype_digit($name))) {
          $names[] = $name;
        }
      }
      $existing = [];
      if ($names !== []) {
        $query = $storage->getQuery()->accessCheck(FALSE)->condition($idKey, $names, 'IN');
        if ($definition->hasKey('bundle')) {
          $query->condition((string) $definition->getKey('bundle'), $bundle);
        }
        $existing = array_map('strval', $query->execute());
      }
      foreach ($members as $member) {
        if (!in_array($member->getName(), $existing, TRUE)) {
          $this->deleteTree($member);
        }
      }
    }
  }

  /**
   * Deletes a resource, and everything in it a chunk at a time.
   */
  private function deleteTree(LwsResourceInterface $resource): void {
    if ($resource->isContainer()) {
      while (($members = $this->resources->membersAfter($resource, NULL, self::CHUNK)) !== []) {
        foreach ($members as $member) {
          $this->deleteTree($member);
        }
      }
    }
    $this->manager->deleteResource($resource);
  }

  /**
   * The links an entity's resource has: the bundle's type, and its page.
   */
  private function metadata(ProjectionInterface $projection, ContentEntityInterface $entity): UserMetadata {
    $type = $projection->typeOf($entity->getEntityTypeId(), $entity->bundle());
    $links = [];
    if ($entity->hasLinkTemplate('canonical')) {
      $page = $entity->toUrl('canonical', ['absolute' => TRUE, 'base_url' => $this->urls->baseUrl()])->toString();
      $links['alternate'] = [['href' => $page, 'type' => 'text/html']];
    }
    return new UserMetadata($type === NULL ? [] : [$type], $links);
  }

  /**
   * Whether two sets of links are the same.
   */
  private static function sameMetadata(UserMetadata $a, UserMetadata $b): bool {
    return $a->types === $b->types && $a->links == $b->links;
  }

  /**
   * The content of a data resource, if it can be read.
   */
  private static function content(LwsResourceInterface $resource): ?string {
    $uri = $resource->getContentFile()?->getFileUri();
    $content = $uri === NULL ? FALSE : @file_get_contents($uri);
    return $content === FALSE ? NULL : $content;
  }

  /**
   * A readable stream of a string.
   *
   * @return resource
   *   The stream, at its start.
   */
  private static function stream(string $content) {
    $stream = fopen('php://temp', 'w+b');
    if ($stream === FALSE) {
      throw new \RuntimeException('No temporary stream.');
    }
    fwrite($stream, $content);
    rewind($stream);
    return $stream;
  }

}
