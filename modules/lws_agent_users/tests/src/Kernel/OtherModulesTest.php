<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_agent_users\Kernel;

use Drupal\lws_agent_users\AgentUsers;
use Drupal\lws_notify\Entity\LwsSubscriptionInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests agent users with lws_identity's agents and lws_notify's subscribers.
 */
#[Group('lws_agent_users')]
#[RunTestsInSeparateProcesses]
final class OtherModulesTest extends AgentUsersKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'file', 'externalauth', 'lws', 'lws_authz', 'lws_storage', 'lws_identity', 'lws_notify', 'lws_agent_users',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('lws_agent_key');
    $this->installEntitySchema('lws_subscription');
    $this->installConfig(['lws_identity', 'lws_notify']);
    $this->createRole([], 'editor', 'Editor');
    $this->allow($this->agentUsers->roleUri('editor'));
  }

  /**
   * Tests that a user's own agent acts as the user, without a link.
   */
  public function testLocalAgent(): void {
    $dora = $this->createUser(['use lws agent identity'], 'dora');
    $agent = $this->container->get('lws_identity.agent_uris')->uriOf($dora);
    $dora->addRole('editor')->save();
    $this->assertSame(200, $this->as($agent, 'GET', '/lws/alice/root/notes')->getStatusCode());
    $this->assertSame((int) $dora->id(), $this->currentUid());

    // Blocked, it is barred, though its document is gone.
    $dora->block()->save();
    $this->assertSame(403, $this->as($agent, 'GET', '/lws/alice/root/notes')->getStatusCode());
    $dora->activate()->save();

    // A user without an agent identity has no agent to act as it.
    $erin = $this->createUser([], 'erin');
    $erin->addRole('editor')->save();
    $this->assertNull($this->agentUsers->userOf($this->container->get('lws_identity.agent_uris')->uriOf($erin)));

    // Nobody else can be linked to it, nor is one provisioned for it.
    $erin->set(AgentUsers::URI_FIELD, $agent);
    $this->assertCount(1, $erin->get(AgentUsers::URI_FIELD)->validate());
    $this->config('lws_agent_users.settings')->set('mode', 'provision')->save();
    $stranger = $this->container->get('lws_identity.agent_uris')->base() . '00000000-0000-4000-8000-000000000000';
    $this->as($stranger, 'GET', '/lws/alice/root/notes');
    $this->assertNull($this->agentUsers->userOf($stranger));
  }

  /**
   * Tests that a subscriber is judged as its user is now.
   */
  public function testSubscriber(): void {
    $bob = $this->linkedUser('bob', self::BOB);
    $subscription = $this->container->get('entity_type.manager')->getStorage('lws_subscription')->create([
      'storage' => $this->loadStorage('alice')->id(),
      'type' => 'WebhookSubscription',
      'agent' => self::BOB,
      'client' => 'https://app.example/id',
      'topic' => [self::STORAGE],
      'inbox' => 'https://inbox.example/',
      'expires' => time() + 3600,
    ]);
    $this->assertInstanceOf(LwsSubscriptionInterface::class, $subscription);
    $subscriptions = $this->container->get('lws_notify.subscriptions');

    $this->assertSame([$this->agentUsers->roleUri('authenticated')], $subscriptions->agentOf($subscription)->groups);
    $bob->addRole('editor')->save();
    $this->assertContains($this->agentUsers->roleUri('editor'), $subscriptions->agentOf($subscription)->groups);
    $this->assertSame('https://app.example/id', $subscriptions->agentOf($subscription)->client);
    $bob->block()->save();
    $this->assertTrue($subscriptions->agentOf($subscription)->blocked);
  }

}
