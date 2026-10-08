<?php

declare(strict_types=1);

namespace Drupal\lws\EventSubscriber;

use Drupal\Core\ParamConverter\ParamNotConvertedException;
use Drupal\Core\Utility\Error;
use Drupal\lws\Agent\Authentication;
use Drupal\lws\Http\BearerChallenge;
use Drupal\lws\Http\LwsHttpException;
use Drupal\lws\Http\ProblemResponse;
use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws\Routing\LwsUrlParser;
use Ebremer\Lws\Http\LinkHeader;
use Ebremer\Lws\LinkRelation;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Renders errors in the LWS URL space as RFC 9457 problem details.
 *
 * A refusal of a request without a valid token becomes a 401 with a Bearer
 * challenge naming the storage as the realm and its authorization server as
 * "as_uri" (LWS Core §5.2.1). A refusal of a valid token stays a 403.
 */
final class LwsExceptionSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly LwsUrlParser $parser,
    private readonly LwsUrlGenerator $urls,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Ahead of core's Fast404ExceptionHtmlSubscriber (200), which would answer
    // a 404 for a resource named like "notes.txt" with an HTML page. Also
    // ahead of the exception logger (50): 4xx responses here are protocol
    // traffic, not site errors, so only server errors are logged.
    return [KernelEvents::EXCEPTION => ['onException', 250]];
  }

  /**
   * Replaces the error response for requests in the LWS URL space.
   */
  public function onException(ExceptionEvent $event): void {
    $request = $event->getRequest();
    $target = $this->parser->parse($request->getPathInfo());
    if ($target === NULL) {
      return;
    }
    $exception = $event->getThrowable();
    $status = 500;
    $headers = [];
    if ($exception instanceof HttpExceptionInterface) {
      $status = $exception->getStatusCode();
      $headers = $exception->getHeaders();
    }
    // A storage that does not exist. Core turns this into a 404 too, but only
    // later, at a lower priority.
    if ($exception instanceof ParamNotConvertedException) {
      $status = 404;
    }
    if ($status >= 500) {
      Error::logException($this->logger, $exception);
    }
    if ($exception instanceof MethodNotAllowedHttpException) {
      $headers['Allow'] = implode(', ', $target->allowedMethods());
    }
    $detail = $exception instanceof LwsHttpException ? $exception->getMessage() : NULL;
    $authentication = Authentication::fromRequest($request);
    if (($status === 401 || $status === 403) && !$authentication->isAuthenticated() && $authentication->realm !== NULL) {
      if ($authentication->asUri === NULL) {
        // No token can be valid, so a challenge would send the client on a
        // fruitless errand.
        $status = 503;
        $detail = 'This storage has no authorization server.';
      }
      else {
        $status = 401;
        $detail = $authentication->errorDescription;
        $headers['WWW-Authenticate'] = BearerChallenge::format([
          'as_uri' => $authentication->asUri,
          'realm' => $authentication->realm,
          'error' => $authentication->error,
          'error_description' => $authentication->errorDescription,
        ]);
        $headers['Link'] = LinkHeader::format($authentication->realm, LinkRelation::STORAGE);
      }
    }
    $event->setResponse(ProblemResponse::create($status, $detail, $this->urls->targetUri($target), $headers));
  }

}
