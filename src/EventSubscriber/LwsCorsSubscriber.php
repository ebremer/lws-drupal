<?php

declare(strict_types=1);

namespace Drupal\lws\EventSubscriber;

use Drupal\lws\Routing\LwsUrlParser;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds CORS headers to every response in the LWS URL space.
 *
 * That is every URL under the prefix, those of storages and those of the
 * authorization server's endpoints alike, and the authorization server's
 * metadata.
 *
 * LWS clients run in browsers on other origins, and authenticate with bearer
 * tokens, never cookies, so any origin may read responses: the token, not the
 * origin, decides what a request may do. Without credentialed requests, the
 * wildcard origin is safe and needs no "Vary: Origin".
 *
 * Core's site-wide CORS middleware can stay off.
 */
final class LwsCorsSubscriber implements EventSubscriberInterface {

  /**
   * The request headers clients may send.
   */
  public const ALLOW_HEADERS = [
    'Authorization',
    'Content-Type',
    'If-Match',
    'If-None-Match',
    'If-Modified-Since',
    'If-Unmodified-Since',
    'Link',
    'Slug',
    'Prefer',
    'Depth',
    'Range',
  ];

  /**
   * The response headers clients may read.
   */
  public const EXPOSE_HEADERS = [
    'Accept-Patch',
    'Accept-Query',
    'Allow',
    'Content-Range',
    'ETag',
    'Last-Modified',
    'Link',
    'Location',
    'Preference-Applied',
    'Vary',
    'WWW-Authenticate',
  ];

  /**
   * How long browsers may cache a preflight answer, in seconds.
   */
  public const MAX_AGE = 600;

  /**
   * The paths of authorization server metadata (LWS Core §5.2.2, RFC 8414).
   */
  public const METADATA_PATHS = ['/.well-known/lws-configuration', '/.well-known/oauth-authorization-server'];

  public function __construct(
    private readonly LwsUrlParser $parser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => ['onResponse']];
  }

  /**
   * Adds the headers.
   */
  public function onResponse(ResponseEvent $event): void {
    $request = $event->getRequest();
    $path = $request->getPathInfo();
    $prefix = $this->parser->prefix();
    if (!$event->isMainRequest() || !(($prefix !== '' && str_starts_with($path, $prefix . '/')) || in_array($path, self::METADATA_PATHS, TRUE))) {
      return;
    }
    $headers = $event->getResponse()->headers;
    $headers->set('Access-Control-Allow-Origin', '*');
    $preflight = $request->isMethod('OPTIONS') && $request->headers->has('Access-Control-Request-Method');
    if (!$preflight) {
      $headers->set('Access-Control-Expose-Headers', implode(', ', self::EXPOSE_HEADERS));
      return;
    }
    // LwsRequestSubscriber, or core for other routes, answered the preflight
    // with the methods the URL allows.
    if ($headers->has('Allow')) {
      $headers->set('Access-Control-Allow-Methods', (string) $headers->get('Allow'));
      $headers->set('Access-Control-Allow-Headers', implode(', ', self::ALLOW_HEADERS));
      $headers->set('Access-Control-Max-Age', (string) self::MAX_AGE);
    }
  }

}
