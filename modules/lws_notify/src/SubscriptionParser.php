<?php

declare(strict_types=1);

namespace Drupal\lws_notify;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\lws\Routing\LwsArea;
use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws\Routing\LwsUrlParser;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\ResourceLinks;
use Drupal\lws_storage\ResourceRepository;
use Ebremer\Lws\Json\Json;
use Ebremer\Lws\SubscriptionType;

/**
 * Reads subscription requests (LWS Core §10.3.1, lws10-notifications-webhook).
 *
 * A request needs a "type" the service offers (WebhookSubscription), a
 * "topic" of resource URIs in the storage, and an http(s) "inbox"; it may ask
 * for an "expires" datetime. Other members are ignored.
 *
 * A topic names one resource: "notes" and "notes/" are two. The storage URI
 * stands for its root container, so that it covers the whole storage.
 * Whether a topic exists does not matter, so that refusing one reveals
 * nothing: a subscription to a resource yet to be created hears of its
 * creation.
 */
final class SubscriptionParser {

  /**
   * The subscription types the notification service offers.
   */
  public const TYPES = [SubscriptionType::WEBHOOK];

  /**
   * The longest inbox URL accepted.
   */
  public const MAX_INBOX_LENGTH = 2048;

  /**
   * An RFC 3339 date-time.
   */
  private const DATE_TIME = '/^\d{4}-\d{2}-\d{2}[Tt]\d{2}:\d{2}:\d{2}(\.\d+)?([Zz]|[+-]\d{2}:\d{2})$/';

  public function __construct(
    private readonly LwsUrlParser $parser,
    private readonly LwsUrlGenerator $urls,
    private readonly ResourceRepository $resources,
    private readonly ResourceLinks $links,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Reads a subscription request.
   *
   * @param mixed $json
   *   The decoded request body.
   * @param \Drupal\lws_storage\Entity\LwsStorageInterface $storage
   *   The storage whose notification service it was sent to.
   *
   * @throws \Drupal\lws_notify\InvalidSubscriptionException
   *   Saying what is wrong with it.
   */
  public function parse(mixed $json, LwsStorageInterface $storage): SubscriptionRequest {
    $request = Json::members($json) ?? throw new InvalidSubscriptionException('A subscription request is a JSON object.');
    $type = $request['type'] ?? NULL;
    if (!is_string($type) || $type === '') {
      throw new InvalidSubscriptionException('A subscription request needs a "type": WebhookSubscription.');
    }
    if (!in_array($type, self::TYPES, TRUE)) {
      throw new InvalidSubscriptionException(sprintf('The notification service offers %s subscriptions only, not %s.', implode(', ', self::TYPES), $type));
    }
    return new SubscriptionRequest(
      $type,
      $this->topics($request['topic'] ?? NULL, $storage),
      $this->inbox($request['inbox'] ?? NULL),
      $this->expires($request['expires'] ?? NULL),
    );
  }

  /**
   * Reads the topics.
   *
   * @return array<string, \Drupal\lws\Access\ResourceContext>
   *   Canonical resource URIs and their access contexts.
   *
   * @throws \Drupal\lws_notify\InvalidSubscriptionException
   */
  private function topics(mixed $value, LwsStorageInterface $storage): array {
    if (!is_array($value) || !array_is_list($value) || $value === []) {
      throw new InvalidSubscriptionException('A subscription request needs a "topic": an array of the URIs of the resources it is about.');
    }
    $max = (int) $this->configFactory->get('lws_notify.settings')->get('limits.topics');
    if (count($value) > $max) {
      throw new InvalidSubscriptionException(sprintf('A subscription may have at most %d topics.', $max));
    }
    $topics = [];
    foreach ($value as $uri) {
      if (!is_string($uri)) {
        throw new InvalidSubscriptionException('Each topic is a URI string.');
      }
      [$canonical, $segments, $container] = $this->resource($uri, $storage);
      if (!isset($topics[$canonical])) {
        $resource = $this->resources->findByPath($storage, implode('/', $segments) . ($container ? '/' : ''));
        $topics[$canonical] = $this->links->context($storage, $segments, $container, $resource);
      }
    }
    return $topics;
  }

  /**
   * The canonical URI of a resource of the storage.
   *
   * @return array{string, list<string>, bool}
   *   The URI, its decoded segments, and whether it is a container.
   *
   * @throws \Drupal\lws_notify\InvalidSubscriptionException
   */
  private function resource(string $uri, LwsStorageInterface $storage): array {
    $base = $this->urls->baseUrl();
    $parts = parse_url($uri);
    $target = NULL;
    if (str_starts_with($uri, $base . '/') && $parts !== FALSE && !isset($parts['query']) && !isset($parts['fragment'])) {
      $target = $this->parser->parse(substr($uri, strlen($base)));
    }
    if ($target !== NULL && $target->storage === $storage->getSlug() && $target->area === LwsArea::Description) {
      // The storage URI covers everything in it: its root container does.
      return [$this->urls->resourceUri($storage->getSlug(), ['root'], TRUE), ['root'], TRUE];
    }
    if ($target === NULL || $target->storage !== $storage->getSlug() || $target->area !== LwsArea::Resource) {
      throw new InvalidSubscriptionException(sprintf('The topic %s is not a resource of the storage %s.', $uri, $this->urls->storageUri($storage->getSlug())));
    }
    return [(string) $this->urls->targetUri($target), $target->segments, $target->container];
  }

  /**
   * Reads the inbox.
   *
   * Whether it may be delivered to is the outbound guard's to say.
   *
   * @throws \Drupal\lws_notify\InvalidSubscriptionException
   */
  private function inbox(mixed $value): string {
    if (!is_string($value) || $value === '') {
      throw new InvalidSubscriptionException('A webhook subscription needs an "inbox": the URL to deliver notifications to.');
    }
    $parts = parse_url($value);
    $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
    if (strlen($value) > self::MAX_INBOX_LENGTH || !in_array($scheme, ['https', 'http'], TRUE) || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\x7f-\xff]/', $value) === 1) {
      throw new InvalidSubscriptionException(sprintf('The inbox must be an absolute http(s) URL of at most %d characters, without user information or a fragment.', self::MAX_INBOX_LENGTH));
    }
    return $value;
  }

  /**
   * Reads the expiry, and limits it to the longest lifetime.
   *
   * @throws \Drupal\lws_notify\InvalidSubscriptionException
   */
  private function expires(mixed $value): ?int {
    $now = $this->time->getCurrentTime();
    $lifetime = (int) $this->configFactory->get('lws_notify.settings')->get('limits.lifetime');
    $limit = $lifetime > 0 ? $now + $lifetime : NULL;
    if ($value === NULL) {
      return $limit;
    }
    $expires = NULL;
    if (is_string($value) && preg_match(self::DATE_TIME, $value) === 1) {
      try {
        $expires = (new \DateTimeImmutable($value))->getTimestamp();
      }
      catch (\Exception) {
      }
    }
    if ($expires === NULL) {
      throw new InvalidSubscriptionException('"expires" must be an RFC 3339 date-time, such as 2026-12-31T23:59:59Z.');
    }
    if ($expires <= $now) {
      throw new InvalidSubscriptionException('"expires" must be in the future.');
    }
    return $limit === NULL ? $expires : min($expires, $limit);
  }

}
