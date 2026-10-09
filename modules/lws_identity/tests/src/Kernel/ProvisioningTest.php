<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_identity\Kernel;

use Drupal\lws_identity\Provisioner;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests creating a storage for each new agent.
 */
#[Group('lws_identity')]
#[RunTestsInSeparateProcesses]
final class ProvisioningTest extends IdentityKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config('lws_identity.settings')->set('provisioning.storage', TRUE)->save();
  }

  /**
   * Tests the storage a new agent gets.
   */
  public function testProvisioned(): void {
    $zoe = $this->agentUser('Zoë Smith');
    $storage = $this->storageOf((int) $zoe->id());
    $this->assertSame('zoe-smith', $storage->getSlug());
    $this->assertSame('Zoë Smith', $storage->label());
    $this->assertSame([$this->uris->uriOf($zoe)], $storage->getControllers());
    $this->assertSame((int) $zoe->id(), $storage->getOwnerId());
    $this->assertSame('zoe-smith', $this->container->get('user.data')->get('lws_identity', (int) $zoe->id(), Provisioner::USER_DATA));

    // A taken slug, or one the URL space reserves, gets a suffix.
    $this->storages->createStorage('alice', 'Alice');
    $this->assertSame('alice-2', $this->storageOf((int) $this->agentUser('alice')->id())->getSlug());
    $this->assertSame('oauth-2', $this->storageOf((int) $this->agentUser('OAuth')->id())->getSlug());

    // Users without an agent get none.
    $bob = $this->createUser([], 'bob');
    $this->assertNull($this->ownedStorage((int) $bob->id()));
  }

  /**
   * Tests an account that gets its agent after it was created.
   */
  public function testLaterAgent(): void {
    $role = $this->createRole(['use lws agent identity']);
    $carol = User::create(['name' => 'carol', 'status' => 0, 'roles' => [$role]]);
    $carol->save();
    $this->assertNull($this->ownedStorage((int) $carol->id()));
    $carol->activate()->save();
    $storage = $this->storageOf((int) $carol->id());

    // One once: a storage an administrator deletes is not made again.
    $storage->delete();
    $carol->save();
    $this->assertNull($this->ownedStorage((int) $carol->id()));
    // Unless asked for.
    $this->assertSame('carol', $this->container->get('lws_identity.provisioner')->provision($carol)->getSlug());
  }

  /**
   * Tests that nothing is provisioned when off, or without the base URL.
   */
  public function testNotProvisioned(): void {
    $this->config('lws_identity.settings')->set('provisioning.storage', FALSE)->save();
    $this->assertNull($this->ownedStorage((int) $this->agentUser('alice')->id()));

    $this->config('lws_identity.settings')->set('provisioning.storage', TRUE)->save();
    $this->config('lws.settings')->set('base_url', '')->save();
    $bob = $this->agentUser('bob');
    $this->assertNull($this->ownedStorage((int) $bob->id()));
    $this->assertNull($this->container->get('user.data')->get('lws_identity', (int) $bob->id(), Provisioner::USER_DATA));
  }

  /**
   * The storage a user owns, which there must be.
   */
  private function storageOf(int $uid): LwsStorageInterface {
    $storage = $this->ownedStorage($uid);
    $this->assertInstanceOf(LwsStorageInterface::class, $storage);
    return $storage;
  }

  /**
   * The storage a user owns, if there is one.
   */
  private function ownedStorage(int $uid): ?LwsStorageInterface {
    $storages = $this->container->get('entity_type.manager')->getStorage('lws_storage')->loadByProperties(['owner' => $uid]);
    $storage = reset($storages);
    return $storage instanceof LwsStorageInterface ? $storage : NULL;
  }

}
