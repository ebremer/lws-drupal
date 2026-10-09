<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_agent_users\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\lws_agent_users\AgentUsers;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that an agent is its user in the LWS URL space only.
 */
#[Group('lws_agent_users')]
#[RunTestsInSeparateProcesses]
final class GuardrailsTest extends AgentUsersKernelTestBase {

  /**
   * Tests a token of an administrator's agent outside the LWS URL space.
   */
  public function testOutsideTheUrlSpace(): void {
    $admin = $this->linkedUser('admin', self::BOB, ['administer users', 'access user profiles', AgentUsers::BYPASS]);
    $token = 'Bearer ' . $this->token(self::BOB);
    $this->assertSame(200, $this->send('GET', '/lws/alice/root/notes', ['Authorization' => $token])->getStatusCode());
    $this->assertSame((int) $admin->id(), $this->currentUid());

    // Each request starts anonymous, as each PHP request does; kernel tests
    // keep the user of the last one.
    $current = $this->container->get('current_user');
    foreach (['/user/' . $admin->id(), '/admin/people', '/lws/oauth/token', '/lws/roles/editor'] as $path) {
      $current->setAccount(new AnonymousUserSession());
      $response = $this->send('GET', $path, ['Authorization' => $token]);
      $this->assertSame(0, $this->currentUid(), $path);
      $this->assertGreaterThanOrEqual(400, $response->getStatusCode(), $path);
    }
  }

  /**
   * Tests that the requests of a user's agent leave no session.
   */
  public function testNoSession(): void {
    $this->linkedUser('bob', self::BOB);
    $this->allow(self::BOB, ['read', 'create']);
    foreach ([
      $this->as(self::BOB, 'GET', '/lws/alice/root/notes'),
      $this->as(self::BOB, 'POST', '/lws/alice/root/', ['Content-Type' => 'text/plain'], 'x'),
    ] as $response) {
      $this->assertLessThan(300, $response->getStatusCode());
      $this->assertSame([], $response->headers->getCookies());
    }
    $database = $this->container->get('database');
    $this->assertTrue(!$database->schema()->tableExists('sessions') || (int) $database->select('sessions')->countQuery()->execute()?->fetchField() === 0);
  }

}
