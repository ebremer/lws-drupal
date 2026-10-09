<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_agent_users\Kernel;

use Drupal\Tests\lws_storage\Kernel\LwsStorageKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\lws_agent_users\AgentUsers;
use Drupal\lws_authz\Policy\AccessPolicy;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base class for kernel tests of agents that act as Drupal users.
 *
 * The storage alice, controlled by ALICE, holds root/notes. The first user,
 * who has every permission, is made first and used by none.
 */
abstract class AgentUsersKernelTestBase extends LwsStorageKernelTestBase {

  use UserCreationTrait;

  /**
   * The controller of the storage alice.
   */
  protected const ALICE = 'https://alice.example/profile#me';

  /**
   * An agent that controls nothing.
   */
  protected const BOB = 'https://bob.example/profile#me';

  /**
   * The storage alice.
   */
  protected const STORAGE = self::BASE . '/lws/alice/';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'externalauth',
    'lws',
    'lws_authz',
    'lws_storage',
    'lws_agent_users',
  ];

  /**
   * The agent users.
   */
  protected AgentUsers $agentUsers;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('externalauth', ['authmap']);
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['lws_agent_users']);
    $this->agentUsers = $this->container->get('lws_agent_users.agent_users');
    // User 1 has every permission, the bypass among them.
    $this->createUser([], 'site-admin');
    $this->storages->createStorage('alice', 'Alice', [self::ALICE]);
    $this->agent = self::ALICE;
    $this->assertSame(201, $this->send('POST', '/lws/alice/root/', ['Slug' => 'notes', 'Content-Type' => 'text/plain'], 'Notes')->getStatusCode());
    $this->agent = NULL;
  }

  /**
   * A user an agent is linked to.
   *
   * @param string $name
   *   The user name.
   * @param string $agent
   *   The agent URI.
   * @param list<string> $permissions
   *   Permissions, in a role of their own.
   */
  protected function linkedUser(string $name, string $agent, array $permissions = []): UserInterface {
    return $this->createUser($permissions, $name, FALSE, [AgentUsers::URI_FIELD => $agent]);
  }

  /**
   * Adds a policy of the storage alice over all of it.
   *
   * @param string $assignee
   *   The assignee.
   * @param list<string> $actions
   *   The actions.
   *
   * @return int
   *   The policy's ID.
   */
  protected function allow(string $assignee, array $actions = ['read']): int {
    $storage = $this->container->get('lws_storage.storage_registry')->get('alice');
    $this->assertNotNull($storage);
    $policy = $this->container->get('lws_authz.policy_parser')->parse(AccessPolicy::document($assignee, $actions, 'StorageResource', [self::STORAGE]), $storage);
    return (int) $this->container->get('lws_authz.policy_store')->add($storage, $policy)->id();
  }

  /**
   * Sends a request as an agent.
   *
   * @param string $agent
   *   The agent URI.
   * @param string $method
   *   The method.
   * @param string $path
   *   The path.
   * @param array<string, string> $headers
   *   Request headers.
   * @param string|null $body
   *   The body.
   */
  protected function as(string $agent, string $method, string $path, array $headers = [], ?string $body = NULL): Response {
    return $this->send($method, $path, $headers + ['Authorization' => 'Bearer ' . $this->token($agent)], $body);
  }

  /**
   * The ID of the current user, as the last request left it.
   */
  protected function currentUid(): int {
    return (int) $this->container->get('current_user')->id();
  }

  /**
   * Reloads a user, as the next request sees it.
   */
  protected function reload(UserInterface $user): UserInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('user');
    $storage->resetCache([(int) $user->id()]);
    $fresh = $storage->load((int) $user->id());
    $this->assertInstanceOf(UserInterface::class, $fresh);
    return $fresh;
  }

}
