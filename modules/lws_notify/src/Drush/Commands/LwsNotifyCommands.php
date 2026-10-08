<?php

declare(strict_types=1);

namespace Drupal\lws_notify\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\lws\Storage\StorageRegistryInterface;
use Drupal\lws_authz\Server\SigningKeys;
use Drupal\lws_notify\Subscriptions;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush commands for subscriptions and the webhook signing keys.
 *
 * No command method may be named create(): that is AutowireTrait's factory.
 */
final class LwsNotifyCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly Subscriptions $subscriptions,
    #[Autowire(service: 'lws_notify.signing_keys')]
    private readonly SigningKeys $keys,
    #[Autowire(service: 'Drupal\lws\Storage\StorageRegistryInterface')]
    private readonly StorageRegistryInterface $storages,
    private readonly TimeInterface $time,
  ) {
    parent::__construct();
  }

  /**
   * Lists the subscriptions to a storage, live or ended.
   *
   * @param string $storage
   *   The storage slug.
   * @param array<string, mixed> $options
   *   The command options.
   */
  #[CLI\Command(name: 'lws:notify:list')]
  #[CLI\Argument(name: 'storage', description: 'The storage slug.')]
  #[CLI\FieldLabels(labels: [
    'uuid' => 'UUID',
    'agent' => 'Agent',
    'client' => 'Client',
    'topics' => 'Topics',
    'inbox' => 'Inbox',
    'expires' => 'Expires',
    'status' => 'Status',
    'failures' => 'Failures',
  ])]
  #[CLI\DefaultTableFields(fields: ['uuid', 'agent', 'topics', 'inbox', 'status'])]
  public function listSubscriptions(string $storage, array $options = ['format' => 'table']): RowsOfFields {
    $ref = $this->storages->get($storage) ?? throw new \InvalidArgumentException(dt('There is no storage @slug.', ['@slug' => $storage]));
    $now = $this->time->getCurrentTime();
    $rows = [];
    foreach ($this->subscriptions->forStorage($ref->id) as $subscription) {
      $expires = $subscription->getExpires();
      $rows[(string) $subscription->uuid()] = [
        'uuid' => $subscription->uuid(),
        'agent' => $subscription->getAgent(),
        'client' => $subscription->getClient() ?? '',
        'topics' => implode(', ', $subscription->getTopics()),
        'inbox' => $subscription->getInbox(),
        'expires' => $expires === NULL ? '' : gmdate('Y-m-d H:i:s', $expires) . 'Z',
        'status' => $subscription->isLive($now) ? 'active' : ($subscription->isActive() ? 'expired' : 'deactivated'),
        'failures' => $subscription->getFailures(),
      ];
    }
    return new RowsOfFields($rows);
  }

  /**
   * Cancels a subscription.
   *
   * @param string $storage
   *   The storage slug.
   * @param string $uuid
   *   The subscription's UUID, the last segment of its URL.
   */
  #[CLI\Command(name: 'lws:notify:cancel')]
  #[CLI\Argument(name: 'storage', description: 'The storage slug.')]
  #[CLI\Argument(name: 'uuid', description: 'The UUID of the subscription.')]
  public function cancel(string $storage, string $uuid): void {
    $ref = $this->storages->get($storage) ?? throw new \InvalidArgumentException(dt('There is no storage @slug.', ['@slug' => $storage]));
    $subscription = $this->subscriptions->load($ref, $uuid) ?? throw new \InvalidArgumentException(dt('The storage @slug has no subscription @uuid.', [
      '@slug' => $storage,
      '@uuid' => $uuid,
    ]));
    $this->subscriptions->delete($subscription);
    $this->logger()?->success(dt('Cancelled the subscription @uuid.', ['@uuid' => $uuid]));
  }

  /**
   * Makes a new webhook signing key for this site.
   *
   * The new key signs from now on, and every storage description publishes
   * it. The previous one stays published for an hour. Run it as the web
   * server's user: a key file is readable by its owner only.
   */
  #[CLI\Command(name: 'lws:notify:key:rotate')]
  #[CLI\Usage(name: 'drush lws:notify:key:rotate', description: 'Makes a new P-256 key and makes it the active one.')]
  public function rotateKey(): void {
    $directory = $this->keys->directory()
      ?? throw new \InvalidArgumentException(dt("No signing key directory is configured: set \$settings['@setting'] or the private file path.", ['@setting' => $this->keys->directorySetting()]));
    $kid = $this->keys->rotate();
    $this->logger()?->success(dt('Made the webhook signing key @kid in @directory; it signs from now on.', [
      '@kid' => $kid,
      '@directory' => $directory,
    ]));
  }

  /**
   * Lists this site's webhook signing keys.
   *
   * @param array<string, mixed> $options
   *   The command options.
   */
  #[CLI\Command(name: 'lws:notify:key:list')]
  #[CLI\FieldLabels(labels: [
    'kid' => 'Key ID',
    'created' => 'Made',
    'status' => 'Status',
  ])]
  #[CLI\DefaultTableFields(fields: ['kid', 'created', 'status'])]
  public function listKeys(array $options = ['format' => 'table']): RowsOfFields {
    $rows = [];
    foreach ($this->keys->inventory() as $i => $key) {
      $rows[$key['kid']] = [
        'kid' => $key['kid'],
        'created' => gmdate('Y-m-d H:i:s', $key['created']) . 'Z',
        'status' => $i === 0 ? 'active' : ($key['published'] ? 'published' : 'retired'),
      ];
    }
    return new RowsOfFields($rows);
  }

}
