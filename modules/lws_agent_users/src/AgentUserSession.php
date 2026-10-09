<?php

declare(strict_types=1);

namespace Drupal\lws_agent_users;

use Drupal\Core\Session\UserSession;
use Drupal\lws\Agent\RequestingAgent;
use Drupal\user\UserInterface;

/**
 * The current user of an LWS request by an agent that acts as a Drupal user.
 *
 * It is that user, so that files the request writes and the log name them,
 * and core records when the user was last seen. It never has a session: the
 * bearer token authenticates each request, in the LWS URL space only. The
 * agent is carried along, as LwsAccount carries it.
 */
final class AgentUserSession extends UserSession {

  public function __construct(
    public readonly RequestingAgent $agent,
    UserInterface $user,
  ) {
    parent::__construct([
      'uid' => (int) $user->id(),
      'name' => $user->getAccountName(),
      'mail' => $user->getEmail(),
      'roles' => $user->getRoles(),
      'timezone' => $user->getTimeZone(),
      'preferred_langcode' => $user->getPreferredLangcode(),
      'preferred_admin_langcode' => $user->getPreferredAdminLangcode(),
      'access' => $user->getLastAccessedTime(),
    ]);
  }

}
