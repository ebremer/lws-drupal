<?php

declare(strict_types=1);

namespace Drupal\lws_notify\Delivery;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\lws\Access\AccessDecisionInterface;
use Drupal\lws\Access\Action;
use Drupal\lws\Access\ResourceContext;
use Drupal\lws\Outbound\OutboundHttp;
use Drupal\lws\Outbound\OutboundHttpException;
use Drupal\lws_authz\Server\SigningKeys;
use Drupal\lws_authz\Token\KeysUnavailableException;
use Drupal\lws_notify\Subscriptions;
use Ebremer\Lws\MediaType;
use Ebremer\Lws\Notification\WebhookSigner;
use Psr\Log\LoggerInterface;

/**
 * Delivers notifications to inboxes (lws10-notifications-webhook).
 *
 * Each delivery is a POST of the notification as application/lws+json,
 * signed with the site's webhook key (RFC 9421, RFC 9530) under the key ID
 * {storage}#{kid}, which the storage description publishes. The inbox URL
 * passes the outbound guard at every attempt, and redirects are not
 * followed.
 *
 * A delivery the inbox could not take (5xx, 429, no answer) is tried again
 * after each of the configured delays: a first retry of a few seconds at the
 * end of the request, later ones from the queue, on cron. A queued retry
 * keeps only the activities the subscriber may still read. A subscription
 * whose deliveries keep failing is deactivated, and one whose inbox answers
 * 410 Gone at once.
 */
final class Deliverer {

  /**
   * The queue of deliveries waiting for a retry, or for cron.
   */
  public const QUEUE = 'lws_notify_delivery';

  /**
   * The longest wait for a retry at the end of a request, in seconds.
   */
  public const INLINE_RETRY = 5;

  public function __construct(
    private readonly OutboundHttp $http,
    private readonly SigningKeys $keys,
    private readonly Subscriptions $subscriptions,
    private readonly AccessDecisionInterface $decisions,
    private readonly QueueFactory $queueFactory,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Delivers notifications made by a request, at its end.
   *
   * Within the time budget; what is left, and retries after the first, wait
   * for cron. Without inline delivery, everything does.
   *
   * @param list<\Drupal\lws_notify\Delivery\Delivery> $deliveries
   *   The deliveries, each on its first attempt.
   */
  public function deliverNow(array $deliveries): void {
    $settings = $this->configFactory->get('lws_notify.settings');
    if (!$settings->get('delivery.inline')) {
      foreach ($deliveries as $delivery) {
        $this->enqueue($delivery, 0);
      }
      return;
    }
    $start = microtime(TRUE);
    $budget = (float) $settings->get('delivery.inline_budget');
    $delays = $this->delays();
    $again = [];
    foreach ($deliveries as $delivery) {
      if (microtime(TRUE) - $start >= $budget) {
        $this->enqueue($delivery, 0);
        continue;
      }
      if ($this->attempt($delivery) === DeliveryOutcome::Retry) {
        $again[] = $delivery;
      }
    }
    if ($again === []) {
      return;
    }
    $delay = $delays[0] ?? NULL;
    if ($delay !== NULL && $delay <= self::INLINE_RETRY && microtime(TRUE) - $start + $delay < $budget) {
      sleep($delay);
      foreach ($again as $delivery) {
        $delivery->attempt++;
        if (microtime(TRUE) - $start >= $budget) {
          $this->enqueue($delivery, 0);
        }
        elseif ($this->attempt($delivery) === DeliveryOutcome::Retry) {
          $this->retryLater($delivery);
        }
      }
      return;
    }
    foreach ($again as $delivery) {
      $this->retryLater($delivery);
    }
  }

  /**
   * Delivers a queued delivery, whose time has come.
   *
   * The subscriber may meanwhile have lost access to some of the resources;
   * only the activities about the others are delivered.
   */
  public function deliverQueued(Delivery $delivery): void {
    if ($delivery->subscription !== NULL) {
      $subscription = $this->subscriptions->loadById($delivery->subscription);
      if ($subscription === NULL || !$subscription->isLive($this->time->getCurrentTime())) {
        return;
      }
      $agent = $this->subscriptions->agentOf($subscription);
      $delivery->filter(fn (array $activity, ?ResourceContext $context): bool => $context !== NULL && $this->decisions->decide($agent, Action::Read, $context)->isPermitted());
      if ($delivery->isEmpty()) {
        return;
      }
    }
    if ($this->attempt($delivery) === DeliveryOutcome::Retry) {
      $this->retryLater($delivery);
    }
  }

  /**
   * Makes one attempt, and records its outcome against the subscription.
   *
   * A Retry outcome means a retry is due: the attempts are not used up. The
   * caller schedules it.
   */
  public function attempt(Delivery $delivery): DeliveryOutcome {
    $subscription = $delivery->subscription === NULL ? NULL : $this->subscriptions->loadById($delivery->subscription);
    if ($delivery->subscription !== NULL && ($subscription === NULL || !$subscription->isLive($this->time->getCurrentTime()))) {
      // Cancelled, expired or deactivated meanwhile.
      return DeliveryOutcome::Failed;
    }
    $body = $delivery->body();
    $status = 0;
    try {
      $response = $this->http->post($delivery->inbox, $body, $this->headers($delivery, $body), (int) $this->configFactory->get('lws_notify.settings')->get('delivery.timeout'));
      $status = $response->status;
      $outcome = DeliveryOutcome::forStatus($status);
      $reason = 'HTTP ' . $status;
    }
    catch (OutboundHttpException $e) {
      // The guard refused the URL, or there was no answer. A refused URL may
      // be refused for a while (a name that resolves to a private address
      // now), so it is retried like no answer.
      $outcome = DeliveryOutcome::Retry;
      $reason = $e->getMessage();
    }
    $retry = $outcome === DeliveryOutcome::Retry && $delivery->attempt <= count($this->delays());
    if ($outcome !== DeliveryOutcome::Delivered) {
      $this->logger->notice('Delivery @attempt to @inbox: @reason.@next', [
        '@attempt' => $delivery->attempt,
        '@inbox' => $delivery->inbox,
        '@reason' => $reason,
        '@next' => $retry ? ' It will be tried again.' : '',
      ]);
    }
    if ($subscription !== NULL && !$retry) {
      $active = $this->subscriptions->recordOutcome($subscription, $status, $outcome === DeliveryOutcome::Delivered, $outcome === DeliveryOutcome::Gone);
      if (!$active) {
        $this->logger->warning('Deactivated the subscription @id: its inbox @inbox answered @reason.', [
          '@id' => $subscription->uuid(),
          '@inbox' => $delivery->inbox,
          '@reason' => $reason,
        ]);
      }
    }
    return $retry ? DeliveryOutcome::Retry : ($outcome === DeliveryOutcome::Retry ? DeliveryOutcome::Failed : $outcome);
  }

  /**
   * The headers of a delivery: its content type and signature.
   *
   * Without a usable key, the delivery is sent unsigned; the status report
   * says why.
   *
   * @return array<string, string>
   *   The headers.
   */
  private function headers(Delivery $delivery, string $body): array {
    try {
      $active = $this->keys->active();
      $signer = new WebhookSigner($active['key'], $delivery->storage . '#' . $active['kid'], clock: fn (): int => $this->time->getCurrentTime());
      return $signer->sign($delivery->inbox, $body, MediaType::LWS_JSON);
    }
    catch (KeysUnavailableException | \InvalidArgumentException $e) {
      $this->logger->error('Sending a notification unsigned: @message', ['@message' => $e->getMessage()]);
      return ['content-type' => MediaType::LWS_JSON];
    }
  }

  /**
   * Queues the next attempt of a delivery, after its delay.
   */
  private function retryLater(Delivery $delivery): void {
    $delays = $this->delays();
    $delay = $delays[$delivery->attempt - 1] ?? NULL;
    if ($delay === NULL) {
      return;
    }
    $delivery->attempt++;
    $this->enqueue($delivery, $delay);
  }

  /**
   * Queues a delivery.
   *
   * @param \Drupal\lws_notify\Delivery\Delivery $delivery
   *   The delivery, set to the attempt to make.
   * @param int $delay
   *   The seconds before it may be tried.
   */
  private function enqueue(Delivery $delivery, int $delay): void {
    $this->queueFactory->get(self::QUEUE)->createItem($delivery->toItem($this->time->getCurrentTime() + $delay));
  }

  /**
   * The seconds to wait before each retry.
   *
   * @return list<int>
   *   The delays: before the second attempt, the third, and so on.
   */
  private function delays(): array {
    $delays = $this->configFactory->get('lws_notify.settings')->get('delivery.retry_delays');
    return is_array($delays) ? array_values(array_map('intval', $delays)) : [];
  }

}
