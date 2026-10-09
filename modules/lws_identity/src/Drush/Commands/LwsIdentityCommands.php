<?php

declare(strict_types=1);

namespace Drupal\lws_identity\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lws_identity\AgentDocuments;
use Drupal\lws_identity\AgentKeys;
use Drupal\lws_identity\AgentUris;
use Drupal\lws_identity\InvalidAgentKeyException;
use Drupal\lws_identity\Provisioner;
use Drupal\user\UserInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Ebremer\Lws\Auth\SigningKey;

/**
 * Drush commands for the agents of this site's users.
 *
 * No command method may be named create(): that is AutowireTrait's factory.
 */
final class LwsIdentityCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly AgentUris $uris,
    private readonly AgentKeys $keys,
    private readonly Provisioner $provisioner,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Shows a user's agent URI and keys.
   *
   * @param string $user
   *   The user.
   * @param array<string, mixed> $options
   *   The command options.
   */
  #[CLI\Command(name: 'lws:agent:show')]
  #[CLI\Argument(name: 'user', description: 'The user: a name, an ID or an e-mail address.')]
  #[CLI\FieldLabels(labels: [
    'kid' => 'Key ID',
    'label' => 'Label',
    'type' => 'Type',
    'expires' => 'Expires',
  ])]
  #[CLI\Usage(name: 'drush lws:agent:show alice', description: 'Shows the agent URI of the user alice, and lists its keys.')]
  public function show(string $user, array $options = ['format' => 'table']): RowsOfFields {
    $account = $this->user($user);
    $this->logger()?->notice(dt('@status: @uri', [
      '@status' => AgentDocuments::hasAgent($account) ? dt('Agent') : dt('No agent (blocked, or without the "use lws agent identity" permission)'),
      '@uri' => $this->uris->uriOf($account),
    ]));
    $rows = [];
    foreach ($this->keys->keysOf((int) $account->id()) as $key) {
      $expires = $key->getExpires();
      $rows[$key->getKeyId()] = [
        'kid' => $key->getKeyId(),
        'label' => (string) $key->label(),
        'type' => AgentKeys::describe($key->getJwk()),
        'expires' => $expires === NULL ? '' : gmdate('Y-m-d\TH:i:s\Z', $expires),
      ];
    }
    return new RowsOfFields($rows);
  }

  /**
   * Adds a public key to a user's agent.
   *
   * @param string $user
   *   The user.
   * @param string $file
   *   The file with the public JWK; "-" for standard input.
   * @param array<string, mixed> $options
   *   The command options.
   */
  #[CLI\Command(name: 'lws:agent:key-add')]
  #[CLI\Argument(name: 'user', description: 'The user: a name, an ID or an e-mail address.')]
  #[CLI\Argument(name: 'file', description: 'A file with the public JWK, or "-" to read it from standard input.')]
  #[CLI\Option(name: 'label', description: 'What the key is for; its key ID by default.')]
  #[CLI\Option(name: 'expires', description: 'When the key stops working: a date or time PHP understands, in UTC, such as 2027-01-01 or "+90 days". Never by default.')]
  #[CLI\Usage(name: 'drush lws:agent:key-add alice key.pub.json --label=laptop', description: 'Adds the public key in key.pub.json to the agent of alice.')]
  public function addKey(string $user, string $file, array $options = ['label' => NULL, 'expires' => NULL]): void {
    $account = $this->user($user);
    $json = $file === '-' ? stream_get_contents(STDIN) : @file_get_contents($file);
    if (!is_string($json)) {
      throw new \RuntimeException(dt('Cannot read @file.', ['@file' => $file]));
    }
    $key = $this->keys->add($account, $json, (string) ($options['label'] ?? ''), self::expires($options['expires']));
    $this->logger()?->success(dt('Added the key @kid to @uri', [
      '@kid' => $key->getKeyId(),
      '@uri' => $this->uris->uriOf($account),
    ]));
  }

  /**
   * Generates a key pair for a user's agent, and prints the private key.
   *
   * @param string $user
   *   The user.
   * @param array<string, mixed> $options
   *   The command options.
   */
  #[CLI\Command(name: 'lws:agent:key-generate')]
  #[CLI\Argument(name: 'user', description: 'The user: a name, an ID or an e-mail address.')]
  #[CLI\Option(name: 'algorithm', description: 'ES256 (the default), ES384 or EdDSA.')]
  #[CLI\Option(name: 'label', description: 'What the key is for; its key ID by default.')]
  #[CLI\Option(name: 'expires', description: 'When the key stops working: a date or time PHP understands, in UTC. Never by default.')]
  #[CLI\Usage(name: 'drush lws:agent:key-generate alice --label=backup-bot > alice.jwk', description: 'Adds a new P-256 key to the agent of alice and writes its private JWK to alice.jwk, which a program can then sign credentials with.')]
  public function generateKey(
    string $user,
    array $options = [
      'algorithm' => 'ES256',
      'label' => NULL,
      'expires' => NULL,
    ],
  ): void {
    $account = $this->user($user);
    try {
      $signingKey = SigningKey::generate((string) ($options['algorithm'] ?? 'ES256'));
    }
    catch (\InvalidArgumentException $e) {
      throw new InvalidAgentKeyException($e->getMessage(), 0, $e);
    }
    $public = $signingKey->publicKey->jwk() + ['alg' => $signingKey->algorithm()];
    $key = $this->keys->add($account, $public, (string) ($options['label'] ?? ''), self::expires($options['expires']));
    // Only this output ever holds the private key: it is not stored.
    $private = $signingKey->jwk() + [
      'alg' => $signingKey->algorithm(),
      'kid' => $key->getKeyId(),
    ];
    $this->output()->writeln(json_encode($private, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $this->logger()?->success(dt('Added the key @kid to @uri. Keep the private key printed above: it is not stored.', [
      '@kid' => $key->getKeyId(),
      '@uri' => $this->uris->uriOf($account),
    ]));
  }

  /**
   * Removes a key from a user's agent.
   *
   * @param string $user
   *   The user.
   * @param string $kid
   *   The key ID.
   */
  #[CLI\Command(name: 'lws:agent:key-delete')]
  #[CLI\Argument(name: 'user', description: 'The user: a name, an ID or an e-mail address.')]
  #[CLI\Argument(name: 'kid', description: 'The key ID, as lws:agent:show lists it.')]
  public function deleteKey(string $user, string $kid): void {
    $account = $this->user($user);
    $key = $this->keys->find((int) $account->id(), $kid) ?? throw new \InvalidArgumentException(dt('The agent of @name has no key @kid.', [
      '@name' => $account->getAccountName(),
      '@kid' => $kid,
    ]));
    $key->delete();
    $this->logger()?->success(dt('Removed the key @kid.', ['@kid' => $kid]));
  }

  /**
   * Creates a storage for a user's agent, controlled by it.
   *
   * @param string $user
   *   The user.
   */
  #[CLI\Command(name: 'lws:agent:provision')]
  #[CLI\Argument(name: 'user', description: 'The user: a name, an ID or an e-mail address.')]
  #[CLI\Usage(name: 'drush lws:agent:provision alice', description: 'Creates a storage named after alice, controlled by her agent and owned by her, even if she had one before.')]
  public function provision(string $user): void {
    $storage = $this->provisioner->provision($this->user($user));
    $this->logger()?->success(dt('Created the storage @slug.', ['@slug' => $storage->getSlug()]));
  }

  /**
   * The user a name, an ID or an e-mail address names.
   *
   * @throws \InvalidArgumentException
   *   When there is none.
   */
  private function user(string $name): UserInterface {
    $storage = $this->entityTypeManager->getStorage('user');
    $users = ctype_digit($name) ? [$storage->load((int) $name)] : $storage->loadByProperties([str_contains($name, '@') ? 'mail' : 'name' => $name]);
    $user = reset($users);
    if (!$user instanceof UserInterface || $user->isAnonymous()) {
      throw new \InvalidArgumentException(dt('There is no user @name.', ['@name' => $name]));
    }
    return $user;
  }

  /**
   * The Unix time an --expires option names, or NULL.
   *
   * @throws \InvalidArgumentException
   */
  private static function expires(mixed $value): ?int {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    $time = strtotime((string) $value . ' UTC');
    if ($time === FALSE) {
      $time = strtotime((string) $value);
    }
    if ($time === FALSE) {
      throw new \InvalidArgumentException(dt('@value is not a date or time.', ['@value' => (string) $value]));
    }
    return $time;
  }

}
