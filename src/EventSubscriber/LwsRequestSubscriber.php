<?php

declare(strict_types=1);

namespace Drupal\lws\EventSubscriber;

use Drupal\lws\Http\LwsHttpException;
use Drupal\lws\Routing\LwsArea;
use Drupal\lws\Routing\LwsUrlParser;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Handles LWS requests that core would otherwise answer before routing.
 *
 * - Malformed paths: core's RedirectLeadingSlashesSubscriber redirects any
 *   path containing "//" to the path with the slashes collapsed, which in
 *   LWS names a different resource. Malformed LWS paths get 400 instead.
 * - OPTIONS: core's OptionsRequestSubscriber answers every OPTIONS request
 *   with the methods of all routes on the internal path. LWS answers with the
 *   methods for the kind of resource the URL addresses. OPTIONS is never
 *   authenticated, since browsers send CORS preflight requests without
 *   credentials, so the answer comes from the shape of the URL alone: looking
 *   the resource up would let anyone probe which resources exist.
 */
final class LwsRequestSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly LwsUrlParser $parser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Ahead of RedirectLeadingSlashesSubscriber and OptionsRequestSubscriber
    // (both 1000).
    return [KernelEvents::REQUEST => ['onRequest', 1010]];
  }

  /**
   * Rejects malformed paths and answers OPTIONS.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    $target = $this->parser->parse($request->getPathInfo());
    if ($target === NULL) {
      return;
    }
    if ($target->area === LwsArea::Malformed) {
      throw LwsHttpException::forUnaddressable($target);
    }
    if (!$request->isMethod('OPTIONS')) {
      return;
    }
    $methods = $target->allowedMethods();
    if ($methods === []) {
      throw LwsHttpException::forUnaddressable($target);
    }
    $event->setResponse(new Response('', Response::HTTP_NO_CONTENT, ['Allow' => implode(', ', $methods)]));
  }

}
