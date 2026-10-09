<?php

declare(strict_types=1);

namespace Drupal\lws_agent_users\EventSubscriber;

use Drupal\lws\Agent\Authentication;
use Drupal\lws\Http\LwsHttpException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Refuses every LWS request of an agent whose Drupal user is blocked.
 *
 * The access decision refuses it every action already; this refuses it what
 * needs none too, such as a storage description or an access request. Its
 * token stays valid: it is the account that is blocked, and unblocking it
 * lets the agent in again.
 */
final class BlockedAgentSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After authentication (AuthenticationSubscriber, 300), which records
    // the agent, and before routing (32).
    return [KernelEvents::REQUEST => ['onRequest', 299]];
  }

  /**
   * Answers 403 to a blocked agent.
   */
  public function onRequest(RequestEvent $event): void {
    if ($event->isMainRequest() && Authentication::fromRequest($event->getRequest())->agent->blocked) {
      throw LwsHttpException::forbidden('The account this agent acts as is blocked.');
    }
  }

}
