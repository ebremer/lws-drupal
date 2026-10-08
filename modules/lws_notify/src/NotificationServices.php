<?php

declare(strict_types=1);

namespace Drupal\lws_notify;

use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws\Storage\StorageKeysInterface;
use Drupal\lws\Storage\StorageServiceInterface;
use Drupal\lws_authz\Server\SigningKeys;
use Drupal\lws_authz\Token\KeysUnavailableException;
use Ebremer\Lws\ServiceType;
use Psr\Log\LoggerInterface;

/**
 * Advertises a storage's notification service and its webhook keys.
 *
 * The service (LWS Core §10.1) offers WebhookSubscription. The keys that sign
 * deliveries are the storage's verification methods, referenced from
 * "authentication", with the ID {storage}#{kid}
 * (lws10-notifications-webhook): the site's key, in each storage's
 * description. The previous key stays published for an hour after a
 * rotation, for deliveries signed before it.
 */
final class NotificationServices implements StorageServiceInterface, StorageKeysInterface {

  public function __construct(
    private readonly LwsUrlGenerator $urls,
    private readonly SigningKeys $keys,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function services(string $storageUri, string $slug): array {
    return [
      [
        'type' => ServiceType::NOTIFICATION,
        'serviceEndpoint' => $this->urls->notificationsUri($slug),
        'subscriptionType' => SubscriptionParser::TYPES,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function verificationMethods(string $storageUri, string $slug): array {
    try {
      // Makes the first key, so that it is published before it signs.
      $this->keys->active();
      $jwks = $this->keys->jwks();
    }
    catch (KeysUnavailableException $e) {
      $this->logger->error('No webhook signing key to publish: @message', ['@message' => $e->getMessage()]);
      return [];
    }
    $methods = [];
    foreach ($jwks['keys'] as $jwk) {
      $methods[] = [
        'id' => $storageUri . '#' . $jwk['kid'],
        'type' => 'JsonWebKey',
        'controller' => $storageUri,
        'publicKeyJwk' => $jwk,
      ];
    }
    return $methods;
  }

}
