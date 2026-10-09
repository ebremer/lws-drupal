<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_agent_users\Kernel;

use Drupal\lws\Access\Action;
use Drupal\lws\Access\ResourceContext;
use Drupal\lws\Agent\RequestingAgent;
use Drupal\lws_authz\Policy\AccessPolicy;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that blocking a user bars its agent everywhere, and only while it is.
 */
#[Group('lws_agent_users')]
#[RunTestsInSeparateProcesses]
final class BlockedAgentTest extends AgentUsersKernelTestBase {

  /**
   * Tests a blocked agent: a controller, with public resources about.
   */
  public function testBlocked(): void {
    $alice = $this->linkedUser('alice', self::ALICE);
    $this->allow(AccessPolicy::PUBLIC);
    $this->assertSame(200, $this->as(self::ALICE, 'GET', '/lws/alice/root/notes')->getStatusCode());

    $alice->block()->save();
    foreach ([
      ['GET', '/lws/alice/root/notes', TRUE],
      ['GET', '/lws/alice/', TRUE],
      ['GET', '/lws/alice/root/', TRUE],
      ['PUT', '/lws/alice/root/notes', TRUE],
      ['GET', '/lws/alice/access/grants/', TRUE],
      // A URL that names nothing, which its problem does not name either.
      ['GET', '/lws/alice/nowhere', FALSE],
    ] as [$method, $path, $named]) {
      $response = $this->as(self::ALICE, $method, $path, ['Content-Type' => 'text/plain'], $method === 'PUT' ? 'x' : NULL);
      $this->assertProblem($response, 403, $named ? self::BASE . $path : NULL);
      $this->assertSame(0, $this->currentUid(), 'A blocked user is no current user.');
    }
    // Without its token, it is anyone.
    $this->assertSame(200, $this->send('GET', '/lws/alice/root/notes')->getStatusCode());

    // The access decision refuses it too, for what is decided outside a
    // request, such as notifications.
    $agent = $this->agentUsers->find(new RequestingAgent(self::ALICE))?->agent;
    $this->assertNotNull($agent);
    $this->assertTrue($agent->blocked);
    $storage = $this->container->get('lws_storage.storage_registry')->get('alice');
    $this->assertNotNull($storage);
    $this->assertFalse($this->container->get('lws_authz.access_decision')->decide($agent, Action::Read, new ResourceContext($storage, self::STORAGE, [], TRUE))->isPermitted());
    $this->assertFalse($this->container->get('lws_authz.access_decision')->forAgent($agent, $storage)->readsSubtree(new ResourceContext($storage, self::STORAGE . 'root/', [self::STORAGE], TRUE)));

    // Unblocked, it is let in again, with the same token.
    $token = $this->token(self::ALICE);
    $this->reload($alice)->activate()->save();
    $this->assertSame(200, $this->send('GET', '/lws/alice/root/notes', ['Authorization' => 'Bearer ' . $token])->getStatusCode());
  }

}
