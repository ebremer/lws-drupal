<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_identity\Kernel;

use Drupal\lws_identity\AgentKeys;
use Drupal\lws_identity\InvalidAgentKeyException;
use Ebremer\Lws\Auth\SigningKey;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests adding and removing agent keys.
 */
#[Group('lws_identity')]
#[RunTestsInSeparateProcesses]
final class AgentKeysTest extends IdentityKernelTestBase {

  /**
   * Tests what one agent's keys may not repeat, and how many it may have.
   */
  public function testLimits(): void {
    $alice = $this->agentUser('alice');
    $jwk = SigningKey::generateP256()->publicKey->jwk();
    $first = $this->keys->add($alice, json_encode($jwk + ['kid' => 'one'], JSON_THROW_ON_ERROR), 'Phone');
    $this->assertSame('Phone', $first->label());
    $this->assertSame((int) $alice->id(), (int) $first->getOwnerId());

    $this->assertRefused('already has a key with the ID one', fn () => $this->keys->add($alice, SigningKey::generateP256()->publicKey->jwk() + ['kid' => 'one']));
    $this->assertRefused('already has this key, as one', fn () => $this->keys->add($alice, $jwk + ['kid' => 'two']));
    $this->assertRefused('would expire before', fn () => $this->keys->add($alice, SigningKey::generateP256()->publicKey->jwk(), '', time() - 1));

    // Another agent may have the same key ID, and the same key.
    $bob = $this->agentUser('bob');
    $this->assertSame('one', $this->keys->add($bob, $jwk + ['kid' => 'one'])->getKeyId());

    for ($n = 2; $n <= AgentKeys::MAX_KEYS; $n++) {
      $this->keys->add($alice, SigningKey::generateEd25519()->publicKey->jwk());
    }
    $this->assertRefused('at most 16 keys', fn () => $this->keys->add($alice, SigningKey::generateEd25519()->publicKey->jwk()));
    $this->assertCount(AgentKeys::MAX_KEYS, $this->keys->keysOf((int) $alice->id()));
    $this->assertSame($first->id(), $this->keys->find((int) $alice->id(), 'one')?->id());
    $this->assertNull($this->keys->find((int) $alice->id(), 'missing'));
  }

  /**
   * Tests that a user's keys go with the account.
   */
  public function testUserDelete(): void {
    $alice = $this->agentUser('alice');
    $bob = $this->agentUser('bob');
    $this->keys->add($alice, SigningKey::generateP256()->publicKey->jwk());
    $this->keys->add($bob, SigningKey::generateP256()->publicKey->jwk());
    $alice->delete();
    $this->assertSame([], $this->keys->keysOf((int) $alice->id()));
    $this->assertCount(1, $this->keys->keysOf((int) $bob->id()));
  }

  /**
   * Asserts that adding a key fails, saying why.
   */
  private function assertRefused(string $reason, callable $add): void {
    try {
      $add();
      $this->fail('The key was added.');
    }
    catch (InvalidAgentKeyException $e) {
      $this->assertStringContainsString($reason, $e->getMessage());
    }
  }

}
