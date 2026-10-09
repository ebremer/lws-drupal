<?php

declare(strict_types=1);

namespace Drupal\lws_identity;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lws_authz\Token\AccessTokenValidator;
use Drupal\lws_identity\Entity\LwsAgentKeyInterface;
use Drupal\user\UserInterface;
use Ebremer\Lws\Auth\VerificationKey;

/**
 * The public keys of agents: checked as they are added, and listed.
 *
 * A key is a public JWK of a type the self-signed suite verifies: EC P-256 or
 * P-384, OKP Ed25519, or RSA of at least 2048 bits. Only its public members
 * are kept, with "alg" and "kid". Its key ID is the JWK's own "kid" when that
 * can be a URI fragment, else its RFC 7638 thumbprint.
 */
final class AgentKeys {

  /**
   * The most keys one agent may have.
   */
  public const MAX_KEYS = 16;

  /**
   * The longest key ID.
   */
  public const MAX_KID_LENGTH = 64;

  /**
   * A key ID: unreserved URI characters, so that it is a fragment as it is.
   */
  private const KID = '/^[A-Za-z0-9._~-]{1,64}$/';

  /**
   * JWK members of the private information class (RFC 7517, RFC 7518).
   */
  private const PRIVATE_MEMBERS = ['d', 'p', 'q', 'dp', 'dq', 'qi', 'oth', 'k'];

  /**
   * The public members of each key type, which its thumbprint covers.
   *
   * RFC 7638 §3.2 for EC and RSA, RFC 8037 §2 for OKP.
   */
  private const PUBLIC_MEMBERS = [
    'EC' => ['crv', 'x', 'y'],
    'OKP' => ['crv', 'x'],
    'RSA' => ['n', 'e'],
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
  ) {}

  /**
   * The keys of a user's agent, oldest first.
   *
   * @return list<\Drupal\lws_identity\Entity\LwsAgentKeyInterface>
   *   The keys.
   */
  public function keysOf(int $uid): array {
    $ids = $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->sort('created')
      ->sort('id')
      ->execute();
    return array_values(array_filter(
      $this->storage()->loadMultiple($ids),
      static fn ($key): bool => $key instanceof LwsAgentKeyInterface,
    ));
  }

  /**
   * The key of a user's agent with a key ID, if there is one.
   */
  public function find(int $uid, string $kid): ?LwsAgentKeyInterface {
    foreach ($this->keysOf($uid) as $key) {
      if ($key->getKeyId() === $kid) {
        return $key;
      }
    }
    return NULL;
  }

  /**
   * Adds a key to a user's agent.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user.
   * @param string|array<array-key, mixed> $jwk
   *   The public JWK, or its JSON.
   * @param string $label
   *   What the key is for; its key ID if empty.
   * @param int|null $expires
   *   When it stops being usable, as a Unix time; NULL for never.
   *
   * @throws \Drupal\lws_identity\InvalidAgentKeyException
   *   When the key cannot be added; the message says why.
   */
  public function add(UserInterface $user, string|array $jwk, string $label = '', ?int $expires = NULL): LwsAgentKeyInterface {
    $public = self::publicJwk(is_string($jwk) ? self::decode($jwk) : $jwk);
    if ($expires !== NULL && $expires <= $this->time->getCurrentTime()) {
      throw new InvalidAgentKeyException('The key would expire before it was added.');
    }
    $existing = $this->keysOf((int) $user->id());
    if (count($existing) >= self::MAX_KEYS) {
      throw new InvalidAgentKeyException(sprintf('An agent may have at most %d keys.', self::MAX_KEYS));
    }
    $thumbprint = self::thumbprint($public);
    foreach ($existing as $key) {
      if ($key->getKeyId() === $public['kid']) {
        throw new InvalidAgentKeyException(sprintf('The agent already has a key with the ID %s.', $public['kid']));
      }
      if (self::thumbprint($key->getJwk()) === $thumbprint) {
        throw new InvalidAgentKeyException(sprintf('The agent already has this key, as %s.', $key->getKeyId()));
      }
    }
    $label = trim($label);
    $key = $this->storage()->create([
      'uid' => $user->id(),
      'label' => mb_substr($label === '' ? $public['kid'] : $label, 0, 128),
      'kid' => $public['kid'],
      'jwk' => json_encode($public, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
      'expires' => $expires,
    ]);
    assert($key instanceof LwsAgentKeyInterface);
    $key->save();
    return $key;
  }

  /**
   * Decodes the JSON of a JWK.
   *
   * @return array<array-key, mixed>
   *   The JWK.
   *
   * @throws \Drupal\lws_identity\InvalidAgentKeyException
   */
  public static function decode(string $json): array {
    try {
      $jwk = json_decode($json, TRUE, 8, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      throw new InvalidAgentKeyException('The key is not JSON.');
    }
    if (!is_array($jwk) || ($jwk !== [] && array_is_list($jwk))) {
      throw new InvalidAgentKeyException('The key is not a JSON object.');
    }
    return $jwk;
  }

  /**
   * The public JWK to keep of a submitted one, with its key ID.
   *
   * @param array<array-key, mixed> $jwk
   *   The submitted JWK.
   *
   * @return array<string, string>
   *   The key type, its public members, "alg" if it named one, and "kid".
   *
   * @throws \Drupal\lws_identity\InvalidAgentKeyException
   */
  public static function publicJwk(array $jwk): array {
    if (isset($jwk['keys'])) {
      throw new InvalidAgentKeyException('This is a JWK Set; add its keys one at a time.');
    }
    $private = array_intersect(self::PRIVATE_MEMBERS, array_keys($jwk));
    if ($private !== []) {
      throw new InvalidAgentKeyException(sprintf('The key has private members (%s): add only the public key, and keep the private key to yourself.', implode(', ', $private)));
    }
    try {
      $key = VerificationKey::fromJwk($jwk);
    }
    catch (\InvalidArgumentException $e) {
      throw new InvalidAgentKeyException(sprintf('The key cannot be used: %s.', rtrim($e->getMessage(), '.')), 0, $e);
    }
    if (isset($jwk['use']) && $jwk['use'] !== 'sig') {
      throw new InvalidAgentKeyException('The key is not for signatures: its "use" is not "sig".');
    }
    if (isset($jwk['key_ops']) && (!is_array($jwk['key_ops']) || !in_array('verify', $jwk['key_ops'], TRUE))) {
      throw new InvalidAgentKeyException('The key is not for verifying signatures: its "key_ops" lack "verify".');
    }
    $type = (string) $jwk['kty'];
    $public = ['kty' => $type];
    foreach (self::PUBLIC_MEMBERS[$type] ?? [] as $member) {
      $public[$member] = (string) $jwk[$member];
    }
    $algorithm = $jwk['alg'] ?? NULL;
    if ($algorithm !== NULL) {
      if (!is_string($algorithm) || !in_array($algorithm, AccessTokenValidator::ALGORITHMS, TRUE) || !$key->supports($algorithm)) {
        throw new InvalidAgentKeyException(sprintf('The key\'s "alg" must be one of %s that suits the key.', implode(', ', AccessTokenValidator::ALGORITHMS)));
      }
      $public['alg'] = $algorithm;
    }
    $kid = $jwk['kid'] ?? NULL;
    if ($kid === NULL) {
      $kid = self::thumbprint($public);
    }
    elseif (!is_string($kid) || preg_match(self::KID, $kid) !== 1) {
      throw new InvalidAgentKeyException(sprintf('The key\'s "kid" must be 1 to %d letters, digits, ".", "_", "~" or "-"; leave it out to use the key\'s thumbprint.', self::MAX_KID_LENGTH));
    }
    $public['kid'] = $kid;
    return $public;
  }

  /**
   * A short description of a key's type, such as "EC P-256 ES256".
   *
   * @param array<array-key, mixed> $jwk
   *   The JWK.
   */
  public static function describe(array $jwk): string {
    return implode(' ', array_filter(
      [$jwk['kty'] ?? NULL, $jwk['crv'] ?? NULL, $jwk['alg'] ?? NULL],
      static fn ($member): bool => is_string($member) && $member !== '',
    ));
  }

  /**
   * The RFC 7638 thumbprint of a public JWK, base64url-encoded SHA-256.
   *
   * @param array<array-key, mixed> $jwk
   *   The JWK; members other than the required ones are ignored.
   */
  public static function thumbprint(array $jwk): string {
    $type = (string) ($jwk['kty'] ?? '');
    $members = ['kty' => $type];
    foreach (self::PUBLIC_MEMBERS[$type] ?? [] as $member) {
      $members[$member] = (string) ($jwk[$member] ?? '');
    }
    ksort($members, SORT_STRING);
    $json = json_encode($members, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return rtrim(strtr(base64_encode(hash('sha256', $json, TRUE)), '+/', '-_'), '=');
  }

  /**
   * The entity storage of keys.
   */
  private function storage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('lws_agent_key');
  }

}
