<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lws_authz\AuthorizationServers;
use Drupal\lws_authz\Entity\TrustedAuthorizationServerInterface;
use Drupal\lws_authz\Server\LocalAuthorizationServer;
use Drupal\lws_authz\Server\SigningKeys;
use Drupal\lws_authz\Token\JsonWebKeySet;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for authorization servers and this site's signing keys.
 *
 * No command method may be named create(): that is AutowireTrait's factory.
 */
final class LwsAuthzCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AuthorizationServers $servers,
    private readonly LocalAuthorizationServer $local,
    private readonly SigningKeys $keys,
  ) {
    parent::__construct();
  }

  /**
   * Trusts an authorization server, or updates one already trusted.
   *
   * @param string $id
   *   The machine name.
   * @param string $issuer
   *   The issuer identifier.
   * @param array<string, mixed> $options
   *   The command options.
   */
  #[CLI\Command(name: 'lws:as:add')]
  #[CLI\Argument(name: 'id', description: 'A machine name for the server: lower-case letters, digits and underscores.')]
  #[CLI\Argument(name: 'issuer', description: 'Its issuer identifier: the "iss" of its tokens.')]
  #[CLI\Option(name: 'label', description: 'A human-readable name; defaults to the issuer.')]
  #[CLI\Option(name: 'jwks', description: 'A file with the JSON Web Key Set of its signing keys, to pin them. Only public key members are kept. Without it, the keys are fetched from its metadata.')]
  #[CLI\Option(name: 'default', description: 'Make it the server of storages that name none.')]
  #[CLI\Usage(name: 'drush lws:as:add main https://as.example --default', description: 'Trusts https://as.example, discovering its keys, for all storages.')]
  public function addServer(
    string $id,
    string $issuer,
    array $options = ['label' => NULL, 'jwks' => NULL, 'default' => FALSE],
  ): void {
    if (preg_match('/^[a-z0-9_]+$/', $id) !== 1) {
      throw new \InvalidArgumentException(dt('The ID must be lower-case letters, digits and underscores.'));
    }
    if ($id === LocalAuthorizationServer::ID) {
      throw new \InvalidArgumentException(dt('"local" names this site\'s own authorization server.'));
    }
    if (!in_array(parse_url($issuer, PHP_URL_SCHEME), ['https', 'http'], TRUE)) {
      throw new \InvalidArgumentException(dt('The issuer must be an HTTPS URL.'));
    }
    $jwks = NULL;
    if (is_string($options['jwks'] ?? NULL)) {
      $json = @file_get_contents($options['jwks']);
      if ($json === FALSE) {
        throw new \InvalidArgumentException(dt('Cannot read @file.', ['@file' => $options['jwks']]));
      }
      $keys = JsonWebKeySet::parse($json);
      if ($keys->count() === 0) {
        throw new \InvalidArgumentException(dt('The key set has no EC P-256, P-384 or Ed25519 signing keys.'));
      }
      $jwks = json_encode($keys->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    $storage = $this->entityTypeManager->getStorage('lws_trusted_as');
    $server = $storage->load($id) ?? $storage->create(['id' => $id]);
    assert($server instanceof TrustedAuthorizationServerInterface);
    $server->set('label', (string) ($options['label'] ?? $issuer));
    $server->set('issuer', $issuer);
    $server->set('jwks', $jwks);
    $server->save();
    if (!empty($options['default'])) {
      $this->configFactory->getEditable('lws_authz.settings')->set('authorization_server', $id)->save();
    }
    $this->logger()?->success(dt('Trusted @issuer as @id@default, with @keys.', [
      '@issuer' => $issuer,
      '@id' => $id,
      '@default' => empty($options['default']) ? '' : dt(', the default'),
      '@keys' => $jwks === NULL ? dt('keys from its metadata') : dt('pinned keys'),
    ]));
  }

  /**
   * Lists this site's authorization server and the trusted ones.
   *
   * @param array<string, mixed> $options
   *   The command options.
   */
  #[CLI\Command(name: 'lws:as:list')]
  #[CLI\FieldLabels(labels: [
    'id' => 'ID',
    'label' => 'Label',
    'issuer' => 'Issuer',
    'keys' => 'Keys',
    'default' => 'Default',
  ])]
  #[CLI\DefaultTableFields(fields: ['id', 'issuer', 'keys', 'default'])]
  public function listServers(array $options = ['format' => 'table']): RowsOfFields {
    $default = $this->servers->defaultId();
    $rows = [
      LocalAuthorizationServer::ID => [
        'id' => LocalAuthorizationServer::ID,
        'label' => (string) $this->local->label(),
        'issuer' => $this->local->getIssuer(),
        'keys' => $this->local->isAvailable() ? 'this site\'s' : 'none: no key directory',
        'default' => $default === LocalAuthorizationServer::ID ? 'yes' : '',
      ],
    ];
    foreach ($this->entityTypeManager->getStorage('lws_trusted_as')->loadMultiple() as $server) {
      if ($server instanceof TrustedAuthorizationServerInterface) {
        $rows[(string) $server->id()] = [
          'id' => $server->id(),
          'label' => $server->label(),
          'issuer' => $server->getIssuer(),
          'keys' => $server->getJwks() === NULL ? 'discovered' : 'pinned',
          'default' => $server->id() === $default ? 'yes' : '',
        ];
      }
    }
    return new RowsOfFields($rows);
  }

  /**
   * Stops trusting an authorization server.
   */
  #[CLI\Command(name: 'lws:as:delete')]
  #[CLI\Argument(name: 'id', description: 'The machine name of the server.')]
  public function deleteServer(string $id): void {
    $server = $this->entityTypeManager->getStorage('lws_trusted_as')->load($id);
    if ($server === NULL) {
      throw new \InvalidArgumentException(dt('There is no authorization server @id.', ['@id' => $id]));
    }
    $server->delete();
    $this->logger()?->success(dt('No longer trusting @id.', ['@id' => $id]));
  }

  /**
   * Makes a new signing key for this site's authorization server.
   *
   * The new key signs from now on. The previous one stays published for as
   * long as tokens it signed may be valid; keys past that are deleted. Run it
   * as the web server's user: a key file is readable by its owner only.
   */
  #[CLI\Command(name: 'lws:key:rotate')]
  #[CLI\Usage(name: 'drush lws:key:rotate', description: 'Makes a new ES256 key and makes it the active one.')]
  public function rotateKey(): void {
    $directory = $this->keys->directory()
      ?? throw new \InvalidArgumentException(dt("No signing key directory is configured: set \$settings['lws_authz_key_directory'] or the private file path."));
    $kid = $this->keys->rotate();
    $this->logger()?->success(dt('Made the signing key @kid in @directory; it signs from now on.', [
      '@kid' => $kid,
      '@directory' => $directory,
    ]));
  }

  /**
   * Lists the signing keys of this site's authorization server.
   *
   * @param array<string, mixed> $options
   *   The command options.
   */
  #[CLI\Command(name: 'lws:key:list')]
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
