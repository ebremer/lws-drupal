<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_agent_users\Kernel;

use Drupal\lws_agent_users\AgentUsers;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests that accounts made for agents go once unseen for long.
 */
#[Group('lws_agent_users')]
#[RunTestsInSeparateProcesses]
final class PruningTest extends AgentUsersKernelTestBase {

  /**
   * Tests which accounts cron deletes.
   */
  public function testPrune(): void {
    $long = time() - 40 * 86400;
    $make = function (string $name, bool $provisioned, int $created, int $access): UserInterface {
      return $this->createUser([], $name, FALSE, [
        AgentUsers::URI_FIELD => 'https://agents.example/' . $name,
        AgentUsers::PROVISIONED_FIELD => $provisioned,
        'created' => $created,
        'access' => $access,
      ]);
    };
    $never = $make('never', TRUE, $long, 0);
    $gone = $make('gone', TRUE, $long, $long);
    $seen = $make('seen', TRUE, $long, time() - 86400);
    $made = $make('made', TRUE, time() - 86400, 0);
    $person = $make('person', FALSE, $long, $long);

    $cron = fn () => $this->container->get('module_handler')->invoke('lws_agent_users', 'cron');
    $cron();
    $this->assertNotNull($this->agentUsers->userOf('https://agents.example/never'), 'Nothing goes while pruning is off.');

    $this->config('lws_agent_users.settings')->set('prune_after_days', 30)->save();
    $messenger = $this->container->get('messenger');
    $messenger->addStatus('Hello');
    $cron();
    $users = $this->container->get('entity_type.manager')->getStorage('user');
    $users->resetCache();
    foreach ([$never, $gone] as $user) {
      $this->assertNull($users->load((int) $user->id()), $user->getAccountName());
      $this->assertNull($this->agentUsers->userOf('https://agents.example/' . $user->getAccountName()));
    }
    foreach ([$seen, $made, $person] as $user) {
      $this->assertNotNull($users->load((int) $user->id()), $user->getAccountName());
    }
    $this->assertSame(['status' => ['Hello']], array_map(static fn (array $list): array => array_map('strval', $list), $messenger->all()), 'Cancelling leaves no messages.');
  }

  /**
   * Tests that an agent's request counts as its account being seen.
   */
  public function testSeen(): void {
    $bob = $this->linkedUser('bob', self::BOB);
    $bob->setLastAccessTime(0)->save();
    $request = Request::create(self::BASE . '/lws/alice/root/notes', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token(self::BOB)]);
    $kernel = $this->container->get('http_kernel');
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    $this->assertGreaterThan(time() - 60, $this->reload($bob)->getLastAccessedTime());
  }

}
