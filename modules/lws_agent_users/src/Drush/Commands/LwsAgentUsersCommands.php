<?php

declare(strict_types=1);

namespace Drupal\lws_agent_users\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lws\Agent\RequestingAgent;
use Drupal\lws_agent_users\AgentUsers;
use Drupal\lws_agent_users\Pruner;
use Drupal\user\UserInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the Drupal users LWS agents act as.
 *
 * No command method may be named create(): that is AutowireTrait's factory.
 */
final class LwsAgentUsersCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly AgentUsers $agentUsers,
    private readonly Pruner $pruner,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Links an agent to a user, which it then acts as.
   *
   * @param string $user
   *   The user.
   * @param string $agent
   *   The agent URI.
   */
  #[CLI\Command(name: 'lws:agent-users:link')]
  #[CLI\Argument(name: 'user', description: 'The user: a name, an ID or an e-mail address.')]
  #[CLI\Argument(name: 'agent', description: 'The agent URI.')]
  #[CLI\Usage(name: 'drush lws:agent-users:link alice https://alice.example/profile#me', description: 'The agent https://alice.example/profile#me acts as the user alice.')]
  public function link(string $user, string $agent): void {
    $account = $this->user($user);
    $account->set(AgentUsers::URI_FIELD, $agent);
    $violations = $account->get(AgentUsers::URI_FIELD)->validate();
    if ($violations->count() > 0) {
      throw new \InvalidArgumentException(strip_tags((string) $violations->get(0)->getMessage()));
    }
    $account->save();
    $this->logger()?->success(dt('@agent acts as @user.', ['@agent' => $agent, '@user' => $account->getAccountName()]));
  }

  /**
   * Unlinks a user's agent.
   *
   * @param string $user
   *   The user.
   */
  #[CLI\Command(name: 'lws:agent-users:unlink')]
  #[CLI\Argument(name: 'user', description: 'The user: a name, an ID or an e-mail address.')]
  public function unlink(string $user): void {
    $account = $this->user($user);
    $account->set(AgentUsers::URI_FIELD, NULL)->save();
    $this->logger()?->success(dt('No agent acts as @user.', ['@user' => $account->getAccountName()]));
  }

  /**
   * Shows which user an agent acts as.
   *
   * @param string $agent
   *   The agent URI.
   */
  #[CLI\Command(name: 'lws:agent-users:find')]
  #[CLI\Argument(name: 'agent', description: 'The agent URI.')]
  public function find(string $agent): void {
    $user = $this->agentUsers->find(new RequestingAgent($agent));
    $account = $user === NULL ? NULL : $this->entityTypeManager->getStorage('user')->load($user->uid);
    if (!$account instanceof UserInterface) {
      $this->logger()?->notice(dt('@agent acts as no user.', ['@agent' => $agent]));
      return;
    }
    $this->logger()?->notice(dt('@agent acts as @user (@uid)@blocked, in the groups @groups.', [
      '@agent' => $agent,
      '@user' => $account->getAccountName(),
      '@uid' => $account->id(),
      '@blocked' => $user->agent->blocked ? dt(', which is blocked') : '',
      '@groups' => implode(' ', $user->agent->groups),
    ]));
  }

  /**
   * Deletes the accounts made for agents unseen for the configured time.
   */
  #[CLI\Command(name: 'lws:agent-users:prune')]
  public function prune(): void {
    $total = 0;
    do {
      $deleted = $this->pruner->prune();
      $total += $deleted;
    } while ($deleted === Pruner::BATCH);
    $this->logger()?->success(dt('Deleted @count accounts.', ['@count' => $total]));
  }

  /**
   * A user by name, ID or e-mail address.
   *
   * @throws \InvalidArgumentException
   */
  private function user(string $name): UserInterface {
    $storage = $this->entityTypeManager->getStorage('user');
    $users = ctype_digit($name) ? [$storage->load((int) $name)] : $storage->loadByProperties([str_contains($name, '@') ? 'mail' : 'name' => $name]);
    $user = reset($users);
    if (!$user instanceof UserInterface || $user->isAnonymous()) {
      throw new \InvalidArgumentException(dt('There is no user @name.', ['@name' => $name]));
    }
    return $user;
  }

}
