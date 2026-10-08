<?php

declare(strict_types=1);

namespace Drupal\lws_notify\Controller;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\lws\Access\AccessDecisionInterface;
use Drupal\lws\Access\Action;
use Drupal\lws\Access\ResourceContext;
use Drupal\lws\Agent\Authentication;
use Drupal\lws\Agent\RequestingAgent;
use Drupal\lws\Http\LwsHttpException;
use Drupal\lws\Http\LwsResponse;
use Drupal\lws\Http\MediaTypeNegotiator;
use Drupal\lws\Http\PaginationCursor;
use Drupal\lws\Http\RequestBody;
use Drupal\lws\Outbound\OutboundHttp;
use Drupal\lws\Outbound\OutboundHttpException;
use Drupal\lws\Routing\LwsTarget;
use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws\Storage\StorageRef;
use Drupal\lws\Storage\StorageRegistryInterface;
use Drupal\lws_notify\Entity\LwsSubscriptionInterface;
use Drupal\lws_notify\InvalidSubscriptionException;
use Drupal\lws_notify\SubscriptionLimitException;
use Drupal\lws_notify\SubscriptionParser;
use Drupal\lws_notify\Subscriptions;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Ebremer\Lws\Http\LinkHeader;
use Ebremer\Lws\Json\Json;
use Ebremer\Lws\LinkRelation;
use Ebremer\Lws\MediaType;
use Ebremer\Lws\ResourceType;
use Ebremer\Lws\Vocabulary;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The notification service of a storage (LWS Core §10.3).
 *
 * An LWS container of webhook subscriptions (lws10-notifications-webhook):
 *
 * - an authenticated agent subscribes with a POST, for itself and through
 *   its client, to resources it may read (§10.3.3);
 * - a listing shows the agent its live subscriptions;
 * - a subscription is shown to, and cancelled by DELETE by, its agent and
 *   the storage's controllers.
 *
 * Without a valid token, a request is answered with the storage's 401
 * challenge.
 */
final class NotificationController implements ContainerInjectionInterface {

  use AutowireTrait;

  /**
   * The media types documents are offered and accepted in.
   */
  private const MEDIA_TYPES = [MediaType::LWS_JSON, MediaType::LD_JSON, MediaType::JSON];

  /**
   * The largest subscription request accepted, in bytes.
   */
  public const MAX_BYTES = 65536;

  /**
   * The subscriptions on one page of a listing.
   */
  public const PAGE_SIZE = 100;

  /**
   * The flood control event of a subscription request, per agent.
   */
  public const FLOOD = 'lws_notify.subscribe';

  /**
   * The subscription requests one agent may make in an hour.
   */
  public const FLOOD_LIMIT = 60;

  public function __construct(
    private readonly Subscriptions $subscriptions,
    private readonly SubscriptionParser $parser,
    private readonly AccessDecisionInterface $decisions,
    private readonly OutboundHttp $http,
    private readonly LwsUrlGenerator $urls,
    private readonly PaginationCursor $cursors,
    private readonly FloodInterface $flood,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'logger.channel.lws_notify')]
    private readonly LoggerInterface $logger,
    #[Autowire(service: 'Drupal\lws\Storage\StorageRegistryInterface')]
    private readonly StorageRegistryInterface $storages,
  ) {}

  /**
   * Serves a GET or HEAD: the listing of the service, or a subscription.
   */
  public function read(LwsTarget $lws_target, Request $request): Response {
    [$storage, $agent, $controls] = $this->prepare($lws_target, $request);
    $type = MediaTypeNegotiator::negotiate($request->headers->get('Accept'), self::MEDIA_TYPES)
      ?? throw LwsHttpException::notAcceptable(self::MEDIA_TYPES);
    $contentType = $type->contentType([Vocabulary::LWS_CONTEXT]);
    if ($lws_target->recordId === NULL) {
      return $this->listing($storage, $lws_target, $agent, $request, $contentType);
    }
    $subscription = $this->entry($storage, $lws_target, $agent, $controls);
    $document = $this->subscriptions->document($storage, $subscription);
    $links = [
      LinkHeader::format($storage->uri, LinkRelation::STORAGE),
      LinkHeader::format(ResourceType::DATA_RESOURCE, LinkRelation::TYPE),
      LinkHeader::format($this->urls->notificationsUri($storage->slug), LinkRelation::UP),
    ];
    $headers = ['Vary' => 'Accept', 'Allow' => implode(', ', $lws_target->allowedMethods())];
    return LwsResponse::json($document, $contentType, $links, LwsResponse::etag(Json::encode($document)), $headers);
  }

  /**
   * Serves a POST: subscribes.
   */
  public function post(LwsTarget $lws_target, Request $request): Response {
    [$storage, $agent] = $this->prepare($lws_target, $request);
    if (!$agent->isAuthenticated()) {
      throw LwsHttpException::forbidden('Subscribing needs an access token.');
    }
    $identifier = hash('sha256', (string) $agent->subject);
    if (!$this->flood->isAllowed(self::FLOOD, self::FLOOD_LIMIT, 3600, $identifier)) {
      throw LwsHttpException::tooManyRequests(3600);
    }
    $this->flood->register(self::FLOOD, 3600, $identifier);

    $type = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));
    if (!in_array($type, self::MEDIA_TYPES, TRUE)) {
      throw LwsHttpException::unsupportedMediaType('Send the subscription request as application/lws+json.');
    }
    $body = RequestBody::read($request, self::MAX_BYTES);
    try {
      $json = Json::decode($body);
    }
    catch (\JsonException) {
      throw LwsHttpException::badRequest('The subscription request is not JSON.');
    }
    try {
      $subscription = $this->parser->parse($json, $this->storageEntity($storage));
    }
    catch (InvalidSubscriptionException $e) {
      throw LwsHttpException::unprocessable($e->getMessage());
    }
    // The subscriber must be able to read everything it subscribes to
    // (§10.3.3).
    foreach ($subscription->topics as $uri => $context) {
      if (!$this->decisions->decide($agent, Action::Read, $context)->isPermitted()) {
        throw LwsHttpException::forbidden(sprintf('The agent may not read %s, so it may not subscribe to it.', $uri));
      }
    }
    try {
      $this->http->assertAllowed($subscription->inbox);
    }
    catch (OutboundHttpException $e) {
      // Why is logged, not told: what a host name resolves to on this side
      // is none of the subscriber's business.
      $this->logger->notice('Refused the inbox of a subscription by @agent: @reason', [
        '@agent' => $agent->subject,
        '@reason' => $e->getMessage(),
      ]);
      throw LwsHttpException::unprocessable('This server does not deliver to that inbox: it delivers to HTTPS URLs of public hosts.');
    }
    try {
      $entity = $this->subscriptions->create($storage, $agent, $subscription);
    }
    catch (SubscriptionLimitException $e) {
      throw LwsHttpException::limitReached($e->getMessage());
    }
    $uri = $this->subscriptions->uri($storage, $entity);
    $this->logger->notice('@agent subscribed at @storage: @uri, delivering to @inbox', [
      '@agent' => $agent->subject,
      '@storage' => $storage->uri,
      '@uri' => $uri,
      '@inbox' => $subscription->inbox,
    ]);
    $document = [
      '@context' => Vocabulary::LWS_CONTEXT,
      'type' => $entity->getType(),
      'subscription' => $uri,
    ];
    $expires = $entity->getExpires();
    if ($expires !== NULL) {
      $document['expires'] = gmdate('Y-m-d\TH:i:s\Z', $expires);
    }
    $links = [
      LinkHeader::format($storage->uri, LinkRelation::STORAGE),
      LinkHeader::format(ResourceType::DATA_RESOURCE, LinkRelation::TYPE),
    ];
    $response = LwsResponse::json($document, MediaType::LWS_JSON, $links, NULL, ['Location' => $uri]);
    $response->setStatusCode(Response::HTTP_CREATED);
    return $response;
  }

  /**
   * Serves a DELETE: cancels a subscription.
   */
  public function delete(LwsTarget $lws_target, Request $request): Response {
    [$storage, $agent, $controls] = $this->prepare($lws_target, $request);
    $subscription = $this->entry($storage, $lws_target, $agent, $controls);
    $this->subscriptions->delete($subscription);
    $this->logger->notice('@agent cancelled @uri', [
      '@agent' => $agent->subject,
      '@uri' => $this->urls->targetUri($lws_target),
    ]);
    return LwsResponse::empty(Response::HTTP_NO_CONTENT);
  }

  /**
   * The storage, the agent, and whether the agent controls the storage.
   *
   * @return array{\Drupal\lws\Storage\StorageRef, \Drupal\lws\Agent\RequestingAgent, bool}
   *   The three.
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   404 for a storage that does not exist, 503 for a blocked one, and a
   *   refusal, which becomes the 401 challenge, for a token that was
   *   rejected.
   */
  private function prepare(LwsTarget $target, Request $request): array {
    $storage = $target->storage === NULL ? NULL : $this->storages->get($target->storage);
    if ($storage === NULL) {
      throw LwsHttpException::notFound();
    }
    if (!$storage->enabled) {
      throw LwsHttpException::serviceUnavailable('This storage is blocked.');
    }
    $authentication = Authentication::fromRequest($request);
    if ($authentication->isFailed()) {
      throw LwsHttpException::forbidden((string) $authentication->errorDescription);
    }
    $agent = $authentication->agent;
    $controls = $agent->isAuthenticated() && $this->decisions->decide($agent, Action::Control, new ResourceContext($storage, $storage->uri, [], TRUE))->isPermitted();
    return [$storage, $agent, $controls];
  }

  /**
   * The storage entity, which the parser reads topics against.
   */
  private function storageEntity(StorageRef $storage): LwsStorageInterface {
    $entity = $this->entityTypeManager->getStorage('lws_storage')->load($storage->id);
    return $entity instanceof LwsStorageInterface ? $entity : throw LwsHttpException::notFound();
  }

  /**
   * A subscription the agent may see: its own, or any for a controller.
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   404 if there is none; a refusal if the agent may not see it.
   */
  private function entry(StorageRef $storage, LwsTarget $target, RequestingAgent $agent, bool $controls): LwsSubscriptionInterface {
    $subscription = $this->subscriptions->load($storage, (string) $target->recordId) ?? throw LwsHttpException::notFound();
    if (!$controls && (!$agent->isAuthenticated() || $subscription->getAgent() !== $agent->subject)) {
      throw LwsHttpException::forbidden('This subscription is not yours.');
    }
    return $subscription;
  }

  /**
   * One page of the agent's live subscriptions, as an LWS container (§8.1).
   */
  private function listing(StorageRef $storage, LwsTarget $target, RequestingAgent $agent, Request $request, string $contentType): Response {
    if (!$agent->isAuthenticated()) {
      throw LwsHttpException::forbidden('Listing subscriptions needs an access token.');
    }
    $uri = $this->urls->notificationsUri($storage->slug);
    $after = 0;
    if ($request->query->has('page')) {
      // The cursor is bound to the agent: a page link is no use to another.
      $decoded = $this->cursors->decode($uri . "\0" . $agent->subject, (string) $request->query->get('page'));
      if ($decoded === NULL || preg_match('/^[1-9][0-9]*$/', $decoded) !== 1) {
        throw LwsHttpException::notFound('This page of the listing does not exist.');
      }
      $after = (int) $decoded;
    }
    [$page, $total, $next] = $this->subscriptions->page($storage, (string) $agent->subject, $after, self::PAGE_SIZE);
    $items = [];
    foreach ($page as $subscription) {
      $items[] = [
        'type' => ['DataResource', $subscription->getType()],
        'id' => $this->subscriptions->uri($storage, $subscription),
        'format' => MediaType::LWS_JSON,
        'modified' => gmdate('Y-m-d\TH:i:s\Z', (int) $subscription->get('created')->value),
      ];
    }
    $links = [
      LinkHeader::format($storage->uri, LinkRelation::STORAGE),
      LinkHeader::format(ResourceType::CONTAINER, LinkRelation::TYPE),
      LinkHeader::format($uri, LinkRelation::FIRST),
    ];
    if ($next !== NULL) {
      $links[] = LinkHeader::format($uri . '?page=' . $this->cursors->encode($uri . "\0" . $agent->subject, (string) $next), LinkRelation::NEXT);
    }
    $body = [
      '@context' => Vocabulary::LWS_CONTEXT,
      'id' => $uri,
      'type' => 'Container',
      'totalItems' => $total,
      'items' => $items,
    ];
    $headers = [
      'Vary' => 'Accept',
      'Allow' => implode(', ', $target->allowedMethods()),
      'Accept-Post' => MediaType::LWS_JSON,
    ];
    return LwsResponse::json($body, $contentType, $links, NULL, $headers);
  }

}
