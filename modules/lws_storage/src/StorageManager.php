<?php

declare(strict_types=1);

namespace Drupal\lws_storage;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lws\Routing\ResourceName;
use Drupal\lws_storage\Entity\LwsResourceInterface;
use Drupal\lws_storage\Entity\LwsStorageInterface;

/**
 * Creates storages and containers, keeping containment consistent.
 *
 * Each operation is one database transaction (LWS Core §7.3).
 */
final class StorageManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly ResourceRepository $resources,
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
   *   The trusted authorization server whose tokens it accepts, by ID; NULL
   *   for the site's default.
   *
   * @throws \InvalidArgumentException
   *   When the storage would be invalid, for example a taken or malformed slug.
   */
  public function createStorage(string $slug, string $label, array $controllers = [], ?int $ownerId = NULL, ?string $authorizationServer = NULL): LwsStorageInterface {
    if ($authorizationServer !== NULL && $this->entityTypeManager->getStorage('lws_trusted_as')->load($authorizationServer) === NULL) {
      throw new \InvalidArgumentException(sprintf('There is no trusted authorization server %s.', $authorizationServer));
    }
    $storage = $this->entityTypeManager->getStorage('lws_storage')->create([
      'slug' => $slug,
      'label' => $label,
      'controllers' => $controllers,
      'owner' => $ownerId,
      'authorization_server' => $authorizationServer,
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
   * Creates an empty container.
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

    $transaction = $this->database->startTransaction();
    try {
      $container = $this->entityTypeManager->getStorage('lws_resource')->create([
        'storage' => $parent->getLwsStorageId(),
        'parent' => $parent->id(),
        'name' => $name . '/',
      ]);
      assert($container instanceof LwsResourceInterface);
      $container->save();
      $this->resources->touch($parent);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    return $container;
  }

}
