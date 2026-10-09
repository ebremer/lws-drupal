<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Authentication;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\lws\Agent\RequestingAgent;

/**
 * The current user of a request that presented a valid LWS access token.
 *
 * LWS agents are not Drupal users (DESIGN.md D4): to Drupal the request is
 * anonymous, and Drupal permissions play no part in LWS decisions. The agent
 * is carried along for code that wants to know who it is, such as logging.
 * An agent that lws_agent_users maps to a user is that user instead.
 */
final class LwsAccount extends AnonymousUserSession {

  public function __construct(
    public readonly RequestingAgent $agent,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public function getAccountName() {
    return (string) $this->agent->subject;
  }

  /**
   * {@inheritdoc}
   *
   * @return string
   *   The agent URI.
   */
  public function getDisplayName() {
    return (string) $this->agent->subject;
  }

}
