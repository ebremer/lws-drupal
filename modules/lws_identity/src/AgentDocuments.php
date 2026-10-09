<?php

declare(strict_types=1);

namespace Drupal\lws_identity;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lws_authz\Cid\LocalDocumentsInterface;
use Drupal\user\UserInterface;
use Ebremer\Lws\Vocabulary;

/**
 * The controlled identifier documents of this site's agents (CID 1.0).
 *
 * A user has an agent, and its document, while the account is active and has
 * the "use lws agent identity" permission. The document names:
 *
 * - the agent's keys, as JsonWebKey verification methods of the
 *   authentication relationship, for self-signed credentials
 *   (lws10-authn-ssi-cid), with "expires" for a key that expires;
 * - the configured OpenID Providers, as lws:OpenIdProvider services, whose ID
 *   Tokens for the agent URI are the agent's (lws10-authn-openid §5).
 *
 * Nothing else about the user is in it: no name, no e-mail address.
 */
final class AgentDocuments implements LocalDocumentsInterface {

  /**
   * The permission that gives a user an agent.
   */
  public const PERMISSION = 'use lws agent identity';

  public function __construct(
    private readonly AgentUris $uris,
    private readonly AgentKeys $keys,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   *
   * Every URL under the agents segment is this site's, whether or not a
   * user has it.
   */
  public function serves(string $url): bool {
    return str_starts_with($url, $this->uris->base());
  }

  /**
   * {@inheritdoc}
   */
  public function document(string $url): ?array {
    $uuid = $this->uris->uuid($url);
    $user = $uuid === NULL ? NULL : $this->agent($uuid);
    return $user === NULL ? NULL : $this->build($user);
  }

  /**
   * The user with an agent whose URI ends in a UUID, if there is one.
   */
  public function agent(string $uuid): ?UserInterface {
    if (!AgentUris::isUuid($uuid)) {
      return NULL;
    }
    $users = $this->entityTypeManager->getStorage('user')->loadByProperties(['uuid' => $uuid]);
    $user = reset($users);
    return $user instanceof UserInterface && self::hasAgent($user) ? $user : NULL;
  }

  /**
   * Whether a user has an agent.
   */
  public static function hasAgent(UserInterface $user): bool {
    return !$user->isAnonymous() && $user->isActive() && $user->hasPermission(self::PERMISSION);
  }

  /**
   * The document of a user's agent.
   *
   * @return array<string, mixed>
   *   The document, as a JSON object.
   */
  public function build(UserInterface $user): array {
    $agent = $this->uris->uriOf($user);
    $document = [
      '@context' => [Vocabulary::CID_CONTEXT],
      'id' => $agent,
    ];
    $methods = [];
    foreach ($this->keys->keysOf((int) $user->id()) as $key) {
      $method = [
        'id' => $agent . '#' . $key->getKeyId(),
        'type' => 'JsonWebKey',
        'controller' => $agent,
        'publicKeyJwk' => $key->getJwk(),
      ];
      $expires = $key->getExpires();
      if ($expires !== NULL) {
        $method['expires'] = gmdate('Y-m-d\TH:i:s\Z', $expires);
      }
      $methods[] = $method;
    }
    if ($methods !== []) {
      $document['authentication'] = $methods;
    }
    $services = [];
    foreach ($this->openIdProviders() as $index => $issuer) {
      $services[] = [
        'id' => $agent . '#openid-provider' . ($index === 0 ? '' : '-' . ($index + 1)),
        'type' => Vocabulary::OPENID_PROVIDER_SERVICE,
        'serviceEndpoint' => $issuer,
      ];
    }
    if ($services !== []) {
      $document['service'] = $services;
    }
    return $document;
  }

  /**
   * The OpenID Providers agent documents name, in order.
   *
   * @return list<string>
   *   Their issuer identifiers.
   */
  public function openIdProviders(): array {
    $providers = $this->configFactory->get('lws_identity.settings')->get('openid_providers');
    return array_values(array_filter(array_map('strval', is_array($providers) ? $providers : []), static fn (string $issuer): bool => $issuer !== ''));
  }

}
