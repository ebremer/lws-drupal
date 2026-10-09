<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_identity\Kernel;

use Drupal\Tests\lws_storage\Kernel\LwsStorageKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\lws_identity\AgentKeys;
use Drupal\lws_identity\AgentUris;
use Drupal\user\UserInterface;

/**
 * Base class for kernel tests of agent identities.
 *
 * Users made with agentUser() have the "use lws agent identity" permission;
 * the first user, who has every permission, is made first and used by none.
 */
abstract class IdentityKernelTestBase extends LwsStorageKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'lws', 'lws_authz', 'lws_storage', 'lws_identity'];

  /**
   * The agent URIs.
   */
  protected AgentUris $uris;

  /**
   * The agent keys.
   */
  protected AgentKeys $keys;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('lws_agent_key');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['lws_identity']);
    $this->uris = $this->container->get('lws_identity.agent_uris');
    $this->keys = $this->container->get('lws_identity.agent_keys');
    // User 1 has every permission.
    $this->createUser([], 'site-admin');
  }

  /**
   * A user with an agent.
   *
   * @param string $name
   *   The user name.
   * @param list<string> $permissions
   *   Further permissions.
   */
  protected function agentUser(string $name, array $permissions = []): UserInterface {
    return $this->createUser(['use lws agent identity', ...$permissions], $name);
  }

}
