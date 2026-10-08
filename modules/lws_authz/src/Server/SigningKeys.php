<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Server;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Site\Settings;
use Drupal\lws_authz\Token\KeysUnavailableException;
use Ebremer\Lws\Auth\SigningKey;
use Ebremer\Lws\Auth\VerificationKey;
use Psr\Log\LoggerInterface;

/**
 * The keys this site's authorization server signs access tokens with.
 *
 * Keys are ES256 (P-256) private JSON Web Keys, one file each, in a directory
 * outside the web root and the database: $settings['lws_authz_key_directory'],
 * or "lws_authz/keys" in the private file system. A file is named for when
 * the key was made and its key ID, the RFC 7638 thumbprint:
 * "{unix time}-{kid}.jwk".
 *
 * The newest key is the active one, which signs. An older key stays in the
 * published key set for as long as tokens it signed may still be valid: the
 * access token lifetime plus the clock skew after the next key was made.
 * Rotating removes the files of keys no longer published.
 *
 * The first key is made when one is first needed.
 *
 * Other modules keep keys for other purposes the same way, under a name of
 * their own: lws_notify's webhook signing keys are "lws_notify" keys, in
 * $settings['lws_notify_key_directory'] or "lws_notify/keys".
 */
final class SigningKeys {

  /**
   * The signature algorithm.
   */
  public const ALGORITHM = 'ES256';

  /**
   * The name of a key file.
   */
  private const FILE = '/^(\d{10})-([A-Za-z0-9_-]{43})\.jwk$/';

  /**
   * Constructs the key store.
   *
   * @param \Drupal\Core\Site\Settings $settings
   *   The site settings.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock backend.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param string $name
   *   The name of the key set: of its directory setting
   *   "{name}_key_directory", its directory "{name}/keys" in the private file
   *   system, and the lock taken while a key is made.
   * @param int|null $retention
   *   How long a key stays published after the next one was made, in
   *   seconds; NULL for the access token lifetime plus the clock skew.
   * @param string $purpose
   *   What the keys are, for messages.
   */
  public function __construct(
    private readonly Settings $settings,
    private readonly LockBackendInterface $lock,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly string $name = 'lws_authz',
    private readonly ?int $retention = NULL,
    private readonly string $purpose = 'access token signing key',
  ) {}

  /**
   * The setting that names the key directory.
   */
  public function directorySetting(): string {
    return $this->name . '_key_directory';
  }

  /**
   * The directory of the key files, or NULL if none is configured.
   */
  public function directory(): ?string {
    $directory = $this->settings->get($this->directorySetting());
    if (is_string($directory) && $directory !== '') {
      return rtrim($directory, '/');
    }
    $private = $this->settings->get('file_private_path');
    return is_string($private) && $private !== '' ? rtrim($private, '/') . '/' . $this->name . '/keys' : NULL;
  }

  /**
   * The keys in the directory, newest first.
   *
   * @return list<array{kid: string, created: int, path: string, published: bool}>
   *   Each key's ID, when it was made, its file and whether it is published.
   */
  public function inventory(): array {
    $directory = $this->directory();
    $keys = [];
    foreach ($directory === NULL ? [] : (@scandir($directory) ?: []) as $name) {
      if (preg_match(self::FILE, $name, $matches) === 1) {
        $keys[] = [
          'kid' => $matches[2],
          'created' => (int) $matches[1],
          'path' => $directory . '/' . $name,
          'published' => TRUE,
        ];
      }
    }
    usort($keys, static fn (array $a, array $b): int => [$b['created'], $b['kid']] <=> [$a['created'], $a['kid']]);
    $now = $this->time->getCurrentTime();
    for ($i = 1; $i < count($keys); $i++) {
      $keys[$i]['published'] = $keys[$i - 1]['created'] + $this->retention() > $now;
    }
    return $keys;
  }

  /**
   * The public keys of the published keys, as a JWK Set.
   *
   * @return array{keys: list<array<string, string>>}
   *   The JWK Set.
   */
  public function jwks(): array {
    $keys = [];
    foreach ($this->inventory() as $entry) {
      if (!$entry['published']) {
        continue;
      }
      $jwk = $this->read($entry);
      if ($jwk !== NULL) {
        $public = VerificationKey::fromJwk($jwk)->jwk();
        $keys[] = $public + ['kid' => $entry['kid'], 'alg' => self::ALGORITHM, 'use' => 'sig'];
      }
    }
    return ['keys' => $keys];
  }

  /**
   * The active key, made if there is none yet.
   *
   * @return array{kid: string, key: \Ebremer\Lws\Auth\SigningKey}
   *   Its ID and the key.
   *
   * @throws \Drupal\lws_authz\Token\KeysUnavailableException
   *   When there is no key directory, or no key can be read or made.
   */
  public function active(): array {
    $newest = $this->inventory()[0] ?? NULL;
    $lock = $this->name . '_signing_key';
    if ($newest === NULL) {
      if ($this->lock->acquire($lock)) {
        try {
          // Another request may have made it while this one waited.
          $newest = $this->inventory()[0] ?? NULL;
          if ($newest === NULL) {
            $this->generate();
            $newest = $this->inventory()[0] ?? NULL;
          }
        }
        finally {
          $this->lock->release($lock);
        }
      }
      else {
        $this->lock->wait($lock);
        $newest = $this->inventory()[0] ?? NULL;
      }
    }
    $jwk = $newest === NULL ? NULL : $this->read($newest);
    if ($newest === NULL || $jwk === NULL) {
      throw new KeysUnavailableException(sprintf('This site has no usable %s.', $this->purpose));
    }
    return ['kid' => $newest['kid'], 'key' => SigningKey::fromJwk($jwk)];
  }

  /**
   * Makes a new key, which becomes the active one.
   *
   * Keys no longer published are deleted.
   *
   * @return string
   *   Its key ID.
   *
   * @throws \Drupal\lws_authz\Token\KeysUnavailableException
   *   When there is no key directory, or the key cannot be written.
   */
  public function rotate(): string {
    $kid = $this->generate();
    foreach ($this->inventory() as $entry) {
      if (!$entry['published'] && !@unlink($entry['path'])) {
        $this->logger->warning('Could not delete the retired signing key @kid.', ['@kid' => $entry['kid']]);
      }
    }
    return $kid;
  }

  /**
   * The JWK thumbprint of a public key (RFC 7638), with SHA-256.
   */
  public static function thumbprint(VerificationKey $key): string {
    $jwk = $key->jwk();
    // The required members, in lexicographic order.
    $members = $jwk['kty'] === 'EC'
      ? ['crv' => $jwk['crv'], 'kty' => $jwk['kty'], 'x' => $jwk['x'], 'y' => $jwk['y']]
      : ['crv' => $jwk['crv'], 'kty' => $jwk['kty'], 'x' => $jwk['x']];
    $json = json_encode($members, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return rtrim(strtr(base64_encode(hash('sha256', $json, TRUE)), '+/', '-_'), '=');
  }

  /**
   * How long a key stays published after the next one was made, in seconds.
   */
  private function retention(): int {
    if ($this->retention !== NULL) {
      return $this->retention;
    }
    $settings = $this->configFactory->get('lws_authz.settings');
    return (int) $settings->get('token_lifetime') + (int) $settings->get('clock_skew');
  }

  /**
   * Writes a new key file.
   *
   * @return string
   *   The key ID.
   *
   * @throws \Drupal\lws_authz\Token\KeysUnavailableException
   */
  private function generate(): string {
    $directory = $this->directory() ?? throw new KeysUnavailableException(sprintf("No signing key directory is configured: set \$settings['%s'] or the private file path.", $this->directorySetting()));
    if (!is_dir($directory) && !@mkdir($directory, 0700, TRUE) && !is_dir($directory)) {
      throw new KeysUnavailableException(sprintf('Cannot create the signing key directory %s.', $directory));
    }
    $key = SigningKey::generateP256();
    $kid = self::thumbprint($key->publicKey);
    $jwk = $key->jwk() + ['kid' => $kid, 'alg' => self::ALGORITHM, 'use' => 'sig'];
    // A new key is always the newest, even when made within the second.
    $created = max($this->time->getCurrentTime(), ($this->inventory()[0]['created'] ?? 0) + 1);
    $path = sprintf('%s/%010d-%s.jwk', $directory, $created, $kid);
    // The file is readable by its owner only before the key is written to
    // it, and the rename makes the whole key appear at once.
    $temporary = $directory . '/.new-' . bin2hex(random_bytes(8));
    $handle = @fopen($temporary, 'x');
    $written = $handle !== FALSE
      && @chmod($temporary, 0600)
      && @fwrite($handle, json_encode($jwk, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) !== FALSE;
    if ($handle !== FALSE) {
      fclose($handle);
    }
    if (!$written || !@rename($temporary, $path)) {
      @unlink($temporary);
      throw new KeysUnavailableException(sprintf('Cannot write a signing key to %s.', $directory));
    }
    $this->logger->notice('Made the @purpose @kid.', ['@purpose' => $this->purpose, '@kid' => $kid]);
    return $kid;
  }

  /**
   * Reads a key file.
   *
   * @param array{kid: string, created: int, path: string, published: bool} $entry
   *   The key's inventory entry.
   *
   * @return array<string, mixed>|null
   *   The private JWK, or NULL if the file is unreadable or not the key its
   *   name says.
   */
  private function read(array $entry): ?array {
    $json = @file_get_contents($entry['path']);
    $jwk = $json === FALSE ? NULL : json_decode($json, TRUE);
    try {
      if (is_array($jwk) && self::thumbprint(VerificationKey::fromJwk($jwk)) === $entry['kid']) {
        return $jwk;
      }
    }
    catch (\InvalidArgumentException) {
    }
    $this->logger->error('The signing key file @path is unreadable, or not the key its name says.', ['@path' => $entry['path']]);
    return NULL;
  }

}
