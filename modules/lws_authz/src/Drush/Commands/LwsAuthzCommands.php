<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lws_authz\Entity\TrustedAuthorizationServerInterface;
use Drupal\lws_authz\Token\JsonWebKeySet;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for trusted authorization servers.
 *
 * No command method may be named create(): that is AutowireTrait's factory.
 */
final class LwsAuthzCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
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
   * Lists the trusted authorization servers.
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
    $default = $this->configFactory->get('lws_authz.settings')->get('authorization_server');
    $rows = [];
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

}
