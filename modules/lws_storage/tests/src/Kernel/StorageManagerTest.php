<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_storage\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\lws\Routing\ResourceName;
use Drupal\lws_storage\Entity\LwsResource;
use Drupal\lws_storage\Entity\LwsResourceInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests storages, containers and the containment hierarchy.
 */
#[Group('lws')]
#[RunTestsInSeparateProcesses]
final class StorageManagerTest extends LwsStorageKernelTestBase {

  /**
   * Tests that a storage is created with its root container.
   */
  public function testCreateStorage(): void {
    $controllers = [
      'https://id.example/alice',
      'did:key:z6MkhaXgBZDvotDkL5257faiztiGiC2QtKLGpbnnEGta2doK',
    ];
    $storage = $this->storages->createStorage('alice', 'Alice', $controllers);
    $this->assertSame('alice', $storage->getSlug());
    $this->assertSame('Alice', $storage->label());
    $this->assertSame($controllers, $storage->getControllers());
    $this->assertTrue($storage->isEnabled());

    $root = $this->resources->root($storage);
    $this->assertTrue($root->isRoot());
    $this->assertTrue($root->isContainer());
    $this->assertNull($root->getParent());
    $this->assertSame('root/', $root->getPath());
    $this->assertSame(['root'], $root->getSegments());
    $this->assertSame(1, $root->getVersion());
  }

  /**
   * Tests slugs that cannot name a storage.
   */
  public function testInvalidSlugs(): void {
    $this->storages->createStorage('alice', 'Alice');
    foreach (['alice', 'Alice', '-alice', 'al_ice', 'oauth', 'agents', str_repeat('a', 64), ''] as $slug) {
      try {
        $this->storages->createStorage($slug, 'x');
        $this->fail("Created a storage with slug '$slug'.");
      }
      catch (\InvalidArgumentException) {
        // Expected.
      }
    }
    $this->assertCount(1, $this->container->get('entity_type.manager')->getStorage('lws_storage')->loadMultiple());
  }

  /**
   * Tests creating containers.
   */
  public function testCreateContainer(): void {
    $storage = $this->storages->createStorage('alice', 'Alice');
    $root = $this->resources->root($storage);
    $notes = $this->storages->createContainer($root, 'Notes');
    $this->assertSame('root/Notes/', $notes->getPath());
    $this->assertSame(['root', 'Notes'], $notes->getSegments());
    $this->assertSame($root->id(), $notes->getParent()?->id());
    $this->assertTrue($notes->isContainer());
    $this->assertFalse($notes->isRoot());

    $deep = $this->storages->createContainer($notes, 'Café menu');
    $this->assertSame('root/Notes/Café menu/', $deep->getPath());

    // Adding a member changes the container's version, and only its own.
    $this->assertSame(2, $this->resources->root($storage)->getVersion());
    $this->assertSame(2, $this->resources->findByPath($storage, 'root/Notes/')?->getVersion());
    $this->assertSame(1, $this->resources->findByPath($storage, 'root/Notes/Café menu/')?->getVersion());

    // Names are case-sensitive.
    $this->storages->createContainer($root, 'notes');
    $this->assertSame(
      ['Notes/', 'notes/'],
      array_map(static fn ($r) => $r->getName(), $this->resources->children($this->resources->root($storage))),
    );
  }

  /**
   * Tests that a container holds one member per name.
   */
  public function testDuplicateName(): void {
    $storage = $this->storages->createStorage('alice', 'Alice');
    $root = $this->resources->root($storage);
    $this->storages->createContainer($root, 'notes');
    $this->expectException(EntityStorageException::class);
    $this->storages->createContainer($this->resources->root($storage), 'notes');
  }

  /**
   * Tests that a failed create changes nothing.
   */
  public function testFailedCreateRollsBack(): void {
    $storage = $this->storages->createStorage('alice', 'Alice');
    $this->storages->createContainer($this->resources->root($storage), 'notes');
    try {
      $this->storages->createContainer($this->resources->root($storage), 'notes');
    }
    catch (EntityStorageException) {
      // Expected.
    }
    $this->assertSame(2, $this->resources->root($storage)->getVersion());
    $this->assertCount(1, $this->resources->children($this->resources->root($storage)));
  }

  /**
   * Tests names a new container cannot have.
   */
  public function testInvalidNames(): void {
    $storage = $this->storages->createStorage('alice', 'Alice');
    $root = $this->resources->root($storage);
    foreach (['', '.', '..', '.hidden', 'a/b', "a\nb", str_repeat('a', ResourceName::MAX_BYTES + 1)] as $name) {
      try {
        $this->storages->createContainer($root, $name);
        $this->fail("Created a container named '$name'.");
      }
      catch (\InvalidArgumentException) {
        // Expected.
      }
    }
    $this->assertSame([], $this->resources->children($root));
  }

  /**
   * Tests that the same path in two storages is two resources.
   */
  public function testStoragesAreSeparate(): void {
    $alice = $this->storages->createStorage('alice', 'Alice');
    $bob = $this->storages->createStorage('bob', 'Bob');
    $this->storages->createContainer($this->resources->root($alice), 'notes');
    $this->assertNotNull($this->resources->findByPath($alice, 'root/notes/'));
    $this->assertNull($this->resources->findByPath($bob, 'root/notes/'));
    $this->assertNotSame($this->resources->root($alice)->id(), $this->resources->root($bob)->id());
  }

  /**
   * Tests that a resource cannot be created outside a container.
   */
  public function testParentInSameStorage(): void {
    $alice = $this->storages->createStorage('alice', 'Alice');
    $bob = $this->storages->createStorage('bob', 'Bob');
    // Entity storage wraps what preSave() throws.
    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('A resource must be created in a container of its own storage.');
    LwsResource::create([
      'storage' => $bob->id(),
      'parent' => $this->resources->root($alice)->id(),
      'name' => 'x/',
    ])->save();
  }

  /**
   * Tests that deleting a storage deletes its resources.
   */
  public function testDeleteStorage(): void {
    $alice = $this->storages->createStorage('alice', 'Alice');
    $bob = $this->storages->createStorage('bob', 'Bob');
    $this->storages->createContainer($this->resources->root($alice), 'notes');
    $alice->delete();
    $resources = $this->container->get('entity_type.manager')->getStorage('lws_resource')->loadMultiple();
    $this->assertCount(1, $resources);
    $remaining = reset($resources);
    $this->assertInstanceOf(LwsResourceInterface::class, $remaining);
    $this->assertSame((int) $bob->id(), $remaining->getLwsStorageId());
  }

}
