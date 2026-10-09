<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_agent_users\Kernel;

use Drupal\lws\Access\Action;
use Drupal\lws\Access\ResourceContext;
use Drupal\lws\Agent\RequestingAgent;
use Drupal\lws_agent_users\AgentUsers;
use Drupal\lws_agent_users\AgentUserSession;
use Drupal\lws_authz\Authentication\LwsAccount;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests agents linked to accounts, in the default link_only mode.
 */
#[Group('lws_agent_users')]
#[RunTestsInSeparateProcesses]
final class LinkedAgentTest extends AgentUsersKernelTestBase {

  /**
   * Tests that a linked agent acts as its user, and in the user's roles.
   */
  public function testRoles(): void {
    $bob = $this->linkedUser('bob', self::BOB);
    $this->createRole([], 'editor', 'Editor');
    $this->allow($this->agentUsers->roleUri('editor'));
    $this->assertSame(self::BASE . '/lws/roles/editor', $this->agentUsers->roleUri('editor'));

    $this->assertSame(403, $this->as(self::BOB, 'GET', '/lws/alice/root/notes')->getStatusCode());
    $this->assertSame((int) $bob->id(), $this->currentUid());
    $this->assertInstanceOf(AgentUserSession::class, $this->container->get('current_user')->getAccount());

    // A change of roles counts from the next request.
    $bob->addRole('editor')->save();
    $this->assertSame(200, $this->as(self::BOB, 'GET', '/lws/alice/root/notes')->getStatusCode());
    $bob->removeRole('editor')->save();
    $this->assertSame(403, $this->as(self::BOB, 'GET', '/lws/alice/root/notes')->getStatusCode());

    // Every user is in "authenticated"; an agent that is no user is not.
    $this->allow($this->agentUsers->roleUri('authenticated'));
    $this->assertSame(200, $this->as(self::BOB, 'GET', '/lws/alice/root/notes')->getStatusCode());
    $this->assertSame(403, $this->as('https://carol.example/#me', 'GET', '/lws/alice/root/notes')->getStatusCode());
    $this->assertSame(0, $this->currentUid());
    $this->assertInstanceOf(LwsAccount::class, $this->container->get('current_user')->getAccount());
    $this->assertCount(2, $this->container->get('entity_type.manager')->getStorage('user')->loadMultiple(), 'No account is made in link_only mode.');

    // A role URI names no resource.
    $this->assertSame(404, $this->send('GET', '/lws/roles/editor')->getStatusCode());
  }

  /**
   * Tests that what the agent writes is the user's.
   */
  public function testAttribution(): void {
    $bob = $this->linkedUser('bob', self::BOB);
    $this->allow(self::BOB, ['read', 'create']);
    $headers = ['Slug' => 'bobs', 'Content-Type' => 'text/plain'];
    $this->assertSame(201, $this->as(self::BOB, 'POST', '/lws/alice/root/', $headers, 'Bob')->getStatusCode());
    $fileStorage = $this->container->get('entity_type.manager')->getStorage('file');
    $files = $fileStorage->loadByProperties(['uid' => $bob->id()]);
    $this->assertCount(1, $files);

    // The content outlives the account, as Anonymous's.
    $bob->delete();
    $fileStorage->resetCache();
    $file = $fileStorage->load(array_key_first($files));
    $this->assertNotNull($file);
    $this->assertSame('0', (string) $file->get('uid')->target_id);
    $this->assertSame(200, $this->as(self::BOB, 'GET', '/lws/alice/root/bobs')->getStatusCode());
  }

  /**
   * Tests the agent URI field: who may see and change it, and what it holds.
   */
  public function testField(): void {
    $bob = $this->linkedUser('bob', self::BOB);
    $carol = $this->createUser([], 'carol');
    $field = $carol->get(AgentUsers::URI_FIELD);

    $admin = $this->createUser(['administer users']);
    $this->assertTrue($field->access('edit', $admin));
    $this->assertFalse($field->access('edit', $carol));
    $this->assertFalse($field->access('view', $carol));
    $this->assertFalse($carol->get(AgentUsers::PROVISIONED_FIELD)->access('edit', $carol));

    $violations = static fn ($user): array => array_map(
      static fn ($violation): string => strip_tags((string) $violation->getMessage()),
      iterator_to_array($user->get(AgentUsers::URI_FIELD)->validate()),
    );
    $carol->set(AgentUsers::URI_FIELD, self::BOB);
    $this->assertSame(['This agent already acts as bob.'], $violations($carol));
    $carol->set(AgentUsers::URI_FIELD, 'not a uri');
    $this->assertStringContainsString('must be an absolute URI', $violations($carol)[0] ?? '');
    $carol->set(AgentUsers::URI_FIELD, 'did:key:z6MkhaXgBZDvotDkL5257faiztiGiC2QtKLGpbnnEGta2doK');
    $this->assertSame([], $violations($carol));
    $carol->save();
    $this->assertSame((int) $carol->id(), (int) $this->agentUsers->userOf('did:key:z6MkhaXgBZDvotDkL5257faiztiGiC2QtKLGpbnnEGta2doK')?->id());
    // Bob's own URI is no violation for bob.
    $this->assertSame([], $violations($bob));

    // Another link replaces the first; none, or no account, ends it.
    $bob->set(AgentUsers::URI_FIELD, 'https://bob.example/other#me')->save();
    $this->assertNull($this->agentUsers->userOf(self::BOB));
    $this->assertSame((int) $bob->id(), (int) $this->agentUsers->userOf('https://bob.example/other#me')?->id());
    $bob->set(AgentUsers::URI_FIELD, NULL)->save();
    $this->assertNull($this->agentUsers->userOf('https://bob.example/other#me'));
    $this->assertFalse($this->container->get('externalauth.authmap')->get((int) $bob->id(), AgentUsers::PROVIDER));
    $carol->delete();
    $this->assertNull($this->agentUsers->userOf('did:key:z6MkhaXgBZDvotDkL5257faiztiGiC2QtKLGpbnnEGta2doK'));
  }

  /**
   * Tests that uninstalling the module leaves agents agents.
   */
  public function testUninstall(): void {
    $bob = $this->linkedUser('bob', self::BOB);
    $this->allow(self::BOB);
    $this->assertSame(200, $this->as(self::BOB, 'GET', '/lws/alice/root/notes')->getStatusCode());
    $this->assertSame((int) $bob->id(), $this->currentUid());

    $this->container->get('module_installer')->uninstall(['lws_agent_users']);
    $this->assertFalse($this->container->has('Drupal\lws\Agent\AgentUsersInterface'));
    $this->assertSame([], $this->container->get('externalauth.authmap')->getAll((int) $bob->id()));
    $this->assertSame(200, $this->as(self::BOB, 'GET', '/lws/alice/root/notes')->getStatusCode());
    $this->assertSame(0, $this->currentUid());
    $this->assertInstanceOf(LwsAccount::class, $this->container->get('current_user')->getAccount());
  }

  /**
   * Tests that Drupal permissions give no LWS access, but for the bypass.
   */
  public function testPermissions(): void {
    $this->linkedUser('admin', self::BOB, ['administer users', 'administer lws storages']);
    $this->assertSame(403, $this->as(self::BOB, 'GET', '/lws/alice/root/notes')->getStatusCode());

    $this->linkedUser('support', 'https://support.example/#me', [AgentUsers::BYPASS]);
    $support = 'https://support.example/#me';
    $this->assertSame(200, $this->as($support, 'GET', '/lws/alice/root/notes')->getStatusCode());
    $this->assertSame(204, $this->as($support, 'PUT', '/lws/alice/root/notes', ['Content-Type' => 'text/plain'], 'Changed')->getStatusCode());
    // Control too, which only controllers have.
    $storage = $this->container->get('lws_storage.storage_registry')->get('alice');
    $this->assertNotNull($storage);
    $control = fn (string $agent): bool => $this->container->get('lws_authz.access_decision')
      ->decide($this->agentUsers->find(new RequestingAgent($agent))->agent ?? new RequestingAgent($agent), Action::Control, new ResourceContext($storage, self::STORAGE, [], TRUE))
      ->isPermitted();
    $this->assertTrue($control($support));
    $this->assertFalse($control(self::BOB));
  }

}
