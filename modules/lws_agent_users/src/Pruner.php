<?php

declare(strict_types=1);

namespace Drupal\lws_agent_users;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Psr\Log\LoggerInterface;

/**
 * Deletes the accounts made for agents that have not been seen for long.
 *
 * An account counts as seen when its agent last made a request (core's
 * "access" time, which the request's current user updates), or, never seen,
 * when it was made. Each is cancelled with "Delete the account and make its
 * content belong to the Anonymous user", as an administrator would.
 */
final class Pruner {

  /**
   * The accounts one run deletes at most.
   */
  public const BATCH = 50;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly MessengerInterface $messenger,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Deletes up to a batch of accounts unseen for the configured time.
   *
   * @return int
   *   The accounts deleted.
   */
  public function prune(int $limit = self::BATCH): int {
    $days = (int) $this->configFactory->get('lws_agent_users.settings')->get('prune_after_days');
    if ($days <= 0) {
      return 0;
    }
    $cutoff = $this->time->getRequestTime() - $days * 86400;
    $uids = $this->entityTypeManager->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', 0, '>')
      ->condition(AgentUsers::PROVISIONED_FIELD, 1)
      ->condition('access', $cutoff, '<')
      ->condition('created', $cutoff, '<')
      ->sort('uid')
      ->range(0, $limit)
      ->execute();
    if ($uids === []) {
      return 0;
    }
    // The messages the cancellation leaves are for nobody here: under
    // automated cron they would reach whoever's request ran it.
    $messages = $this->messenger->all();
    foreach ($uids as $uid) {
      user_cancel([], (int) $uid, 'user_cancel_reassign');
    }
    $batch = &batch_get();
    $batch['progressive'] = FALSE;
    batch_process();
    $this->messenger->deleteAll();
    foreach ($messages as $type => $list) {
      foreach ($list as $message) {
        $this->messenger->addMessage($message, $type);
      }
    }
    $this->logger->notice('Deleted @count accounts of agents unseen for @days days.', [
      '@count' => count($uids),
      '@days' => $days,
    ]);
    return count($uids);
  }

}
