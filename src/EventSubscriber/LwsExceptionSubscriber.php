<?php

declare(strict_types=1);

namespace Drupal\lws\EventSubscriber;

use Drupal\Core\Utility\Error;
use Drupal\lws\Http\LwsHttpException;
use Drupal\lws\Http\ProblemResponse;
use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws\Routing\LwsUrlParser;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Renders errors in the LWS URL space as RFC 9457 problem details.
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
    $target = $this->parser->parse($event->getRequest()->getPathInfo());
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
    if ($status >= 500) {
      Error::logException($this->logger, $exception);
    }
    if ($exception instanceof MethodNotAllowedHttpException) {
      $headers['Allow'] = implode(', ', $target->allowedMethods());
    }
    $detail = $exception instanceof LwsHttpException ? $exception->getMessage() : NULL;
    $event->setResponse(ProblemResponse::create($status, $detail, $this->urls->targetUri($target), $headers));
  }

}
