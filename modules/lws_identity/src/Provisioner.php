<?php

declare(strict_types=1);

namespace Drupal\lws_identity;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lws\Routing\LwsUrlParser;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\StorageManager;
use Drupal\user\UserDataInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Creates a storage for a user's agent (lws_identity.settings:provisioning).
 *
 * The storage is controlled by the agent URI and owned by the user, and its
 * slug comes from the user name. A user gets one once, when the account first
 * has an agent: user data records it, so a storage an administrator deletes
 * is not made again. The agent URI is recorded in the storage, so it needs
 * the configured base URL (lws.settings:base_url), not one taken from a
 * request. Storages need lws_storage.
 */
final class Provisioner {

  /**
   * The user data name under which a provisioned storage's slug is kept.
   */
  public const USER_DATA = 'provisioned_storage';

  /**
   * The longest slug made from a user name, which leaves room for a suffix.
   */
  private const MAX_BASE = 56;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AgentUris $uris,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly UserDataInterface $userData,
    private readonly TransliterationInterface $transliteration,
    private readonly LoggerInterface $logger,
    private readonly ?StorageManager $storages = NULL,
  ) {}

  /**
   * Whether new agents get storages: the setting, with lws_storage enabled.
   */
  public function enabled(): bool {
    return $this->storages !== NULL && (bool) $this->configFactory->get('lws_identity.settings')->get('provisioning.storage');
  }

  /**
   * Provisions a storage for a user who has an agent and has had none.
   *
   * Errors are logged rather than thrown: they must not keep the account from
   * being saved.
   */
  public function provisionIfNeeded(UserInterface $user): ?LwsStorageInterface {
    if (!$this->enabled() || !AgentDocuments::hasAgent($user) || $this->userData->get('lws_identity', (int) $user->id(), self::USER_DATA) !== NULL) {
      return NULL;
    }
    try {
      return $this->provision($user);
    }
    catch (\InvalidArgumentException | \LogicException $e) {
      $this->logger->error('No LWS storage was created for @name: @reason', [
        '@name' => $user->getAccountName(),
        '@reason' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Creates a storage for a user's agent.
   *
   * @throws \LogicException
   *   When lws_storage is not enabled, the user has no agent, or the base URL
   *   is not configured.
   * @throws \InvalidArgumentException
   *   When the storage cannot be created.
   */
  public function provision(UserInterface $user): LwsStorageInterface {
    if ($this->storages === NULL) {
      throw new \LogicException('Storages need the LWS Storage module.');
    }
    if (!AgentDocuments::hasAgent($user)) {
      throw new \LogicException('The user has no agent: the account is blocked, or lacks the "use lws agent identity" permission.');
    }
    if (trim((string) $this->configFactory->get('lws.settings')->get('base_url')) === '') {
      throw new \LogicException('The canonical base URL of LWS (lws.settings:base_url) is not set, and the storage would record an agent URI taken from a request.');
    }
    $storage = $this->storages->createStorage(
      $this->freeSlug(self::slugBase($user->getAccountName(), $this->transliteration), (int) $user->id()),
      $user->getAccountName(),
      [$this->uris->uriOf($user)],
      (int) $user->id(),
    );
    $this->userData->set('lws_identity', (int) $user->id(), self::USER_DATA, $storage->getSlug());
    $this->logger->info('Created the LWS storage @slug for @name.', [
      '@slug' => $storage->getSlug(),
      '@name' => $user->getAccountName(),
    ]);
    return $storage;
  }

  /**
   * The slug a user name suggests: lower-case ASCII letters, digits, hyphens.
   */
  public static function slugBase(string $name, TransliterationInterface $transliteration): string {
    $slug = strtolower($transliteration->transliterate($name, 'en', '-'));
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
    $slug = rtrim(substr($slug, 0, self::MAX_BASE), '-');
    return $slug === '' ? 'user' : $slug;
  }

  /**
   * The first slug from a base that no storage has and the URL space allows.
   */
  private function freeSlug(string $base, int $uid): string {
    $storage = $this->entityTypeManager->getStorage('lws_storage');
    for ($n = 1; $n <= 20; $n++) {
      $slug = $n === 1 ? $base : $base . '-' . $n;
      if (!in_array($slug, LwsUrlParser::RESERVED, TRUE) && $storage->loadByProperties(['slug' => $slug]) === []) {
        return $slug;
      }
    }
    return 'user-' . $uid;
  }

}
