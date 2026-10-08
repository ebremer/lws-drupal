<?php

declare(strict_types=1);

namespace Drupal\lws_notify\Delivery;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DestructableInterface;
use Drupal\lws\Access\AccessDecisionInterface;
use Drupal\lws\Access\Action;
use Drupal\lws\Access\ResourceContext;
use Drupal\lws\Agent\Authentication;
use Drupal\lws\Agent\RequestingAgent;
use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws_authz\AccessService\AccessRecordEvent;
use Drupal\lws_authz\Entity\LwsAccessRecordInterface;
use Drupal\lws_notify\Subscriptions;
use Drupal\lws_storage\LwsResourceEvent;
use Ebremer\Lws\ActivityType;
use Ebremer\Lws\ResourceType;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Turns changes into notifications, and sends them when the request ends.
 *
 * A change to a resource goes to each live subscription that covers it,
 * and that the subscriber, its agent through its client, may read as the
 * change is made (LWS Core §10.3.3). A deleted resource is judged as it was.
 *
 * A new or deleted access request or grant goes to the inbox it names, and a
 * grant made by approving a request to the request's inbox too (§11.6).
 *
 * The activities of one request are batched, one notification per
 * subscription or inbox, and delivered after the response is sent.
 */
final class Notifier implements EventSubscriberInterface, DestructableInterface {

  /**
   * The deliveries made so far, by subscription or inbox.
   *
   * @var array<string, list<\Drupal\lws_notify\Delivery\Delivery>>
   */
  private array $pending = [];

  public function __construct(
    private readonly Subscriptions $subscriptions,
    private readonly AccessDecisionInterface $decisions,
    private readonly Deliverer $deliverer,
    private readonly LwsUrlGenerator $urls,
    private readonly UuidInterface $uuid,
    private readonly TimeInterface $time,
    private readonly RequestStack $requestStack,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   *
   * @return array<string, string|array{string, int}>
   *   The events and their listeners.
   */
  public static function getSubscribedEvents(): array {
    return [
      LwsResourceEvent::CREATED => 'onResource',
      LwsResourceEvent::UPDATED => 'onResource',
      LwsResourceEvent::METADATA_UPDATED => 'onResource',
      LwsResourceEvent::DELETED => 'onResource',
      AccessRecordEvent::CREATED => 'onAccessRecord',
      AccessRecordEvent::DELETED => 'onAccessRecord',
      KernelEvents::TERMINATE => 'flush',
    ];
  }

  /**
   * Notifies the subscriptions that cover a changed resource.
   */
  public function onResource(LwsResourceEvent $event, string $name): void {
    try {
      $resource = $event->resource;
      $activity = NULL;
      foreach ($this->subscriptions->covering($resource) as $subscription) {
        $agent = new RequestingAgent($subscription->getAgent(), $subscription->getClient());
        if (!$this->decisions->decide($agent, Action::Read, $resource)->isPermitted()) {
          continue;
        }
        $activity ??= Activities::forResource($name, $event, $this->uuid->generate(), $this->actor());
        $this->add('subscription:' . $subscription->id(), $subscription->getInbox(), $resource->storage->uri, (int) $subscription->id(), $activity, $resource);
      }
    }
    catch (\Throwable $e) {
      // The change is made; a notification that cannot be is no reason to
      // fail the request.
      $this->logger->error('No notifications of a change to @uri: @message', [
        '@uri' => $event->resource->uri,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Notifies the inboxes an access request or grant names.
   */
  public function onAccessRecord(AccessRecordEvent $event, string $name): void {
    $inboxes = array_unique(array_filter([$event->record->getInbox(), $event->request?->getInbox()], static fn (?string $inbox): bool => $inbox !== NULL));
    if ($inboxes === []) {
      return;
    }
    try {
      $grant = $event->record->getKind() === LwsAccessRecordInterface::GRANT;
      $activity = Activities::forAccessRecord(
        $name === AccessRecordEvent::CREATED ? ActivityType::CREATE : ActivityType::DELETE,
        $event->uri,
        $grant ? ResourceType::ACCESS_GRANT : ResourceType::ACCESS_REQUEST,
        $this->urls->accessUri($event->storage->slug, $grant ? 'grants' : 'requests'),
        $this->uuid->generate(),
        $this->time->getCurrentMicroTime(),
        $this->actor(),
      );
      foreach ($inboxes as $inbox) {
        $this->add('inbox:' . $inbox, $inbox, $event->storage->uri, NULL, $activity);
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('No notification of @uri: @message', ['@uri' => $event->uri, '@message' => $e->getMessage()]);
    }
  }

  /**
   * Delivers what the request made, once its response is sent.
   */
  public function flush(): void {
    // What comes next, such as the next request of a long-running process,
    // may find other subscriptions.
    $this->subscriptions->reset();
    if ($this->pending === []) {
      return;
    }
    $deliveries = array_merge(...array_values($this->pending));
    $this->pending = [];
    try {
      $this->deliverer->deliverNow($deliveries);
    }
    catch (\Throwable $e) {
      $this->logger->error('Delivering notifications failed: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * {@inheritdoc}
   *
   * Outside a web request, as in Drush, the kernel ends without a terminate
   * event.
   */
  public function destruct(): void {
    $this->flush();
  }

  /**
   * Adds an activity to the delivery for a subscription or inbox.
   *
   * @param string $key
   *   The subscription or inbox.
   * @param string $inbox
   *   The inbox URL.
   * @param string $storage
   *   The storage URI.
   * @param int|null $subscription
   *   The subscription ID, if any.
   * @param array<string, mixed> $activity
   *   The activity.
   * @param \Drupal\lws\Access\ResourceContext|null $context
   *   For a subscription, the access context of the activity's resource.
   */
  private function add(string $key, string $inbox, string $storage, ?int $subscription, array $activity, ?ResourceContext $context = NULL): void {
    $deliveries = $this->pending[$key] ?? [];
    $last = $deliveries === [] ? NULL : $deliveries[count($deliveries) - 1];
    if ($last === NULL || $last->isFull()) {
      $last = new Delivery($inbox, $storage, $subscription);
      $deliveries[] = $last;
    }
    $last->add($activity, $context);
    $this->pending[$key] = $deliveries;
  }

  /**
   * The agent who made the change, if activities are to name it.
   *
   * Withheld unless configured (Privacy §17.2).
   */
  private function actor(): ?string {
    if (!$this->configFactory->get('lws_notify.settings')->get('include_actor')) {
      return NULL;
    }
    $request = $this->requestStack->getCurrentRequest();
    return $request === NULL ? NULL : Authentication::fromRequest($request)->agent->subject;
  }

}
