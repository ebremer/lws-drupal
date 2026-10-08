<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\lws\Storage\StorageRef;
use Drupal\lws_authz\Policy\AccessPolicy;
use Drupal\lws_authz\Policy\AccessPolicyParser;
use Drupal\lws_authz\Policy\PolicyStore;
use Drupal\lws_storage\StorageRegistry;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Ebremer\Lws\Json\Json;

/**
 * Drush commands for the access policies of storages.
 *
 * No command method may be named create(): that is AutowireTrait's factory.
 */
final class LwsPolicyCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly StorageRegistry $storages,
    private readonly AccessPolicyParser $parser,
    private readonly PolicyStore $policies,
  ) {
    parent::__construct();
  }

  /**
   * Adds an access policy to a storage.
   *
   * @param string $storage
   *   The storage slug.
   * @param string|null $assignee
   *   The agent URI, "public" or "authenticated".
   * @param array<string, mixed> $options
   *   The command options.
   */
  #[CLI\Command(name: 'lws:policy:add')]
  #[CLI\Argument(name: 'storage', description: 'The storage slug.')]
  #[CLI\Argument(name: 'assignee', description: 'The agent URI; "public" for everyone, or "authenticated" for every authenticated agent. Not with --json.')]
  #[CLI\Option(name: 'action', description: 'read, create, modify or delete. Repeat for several; read by default.')]
  #[CLI\Option(name: 'target', description: 'A resource URI; a container includes everything in it. Repeat for several; the root container by default.')]
  #[CLI\Option(name: 'target-type', description: 'StorageResource (the default), Container or DataResource.')]
  #[CLI\Option(name: 'until', description: 'An xsd:dateTime with a time zone, after which it permits nothing.')]
  #[CLI\Option(name: 'client', description: 'The client_id of the only client it permits.')]
  #[CLI\Option(name: 'json', description: 'A file with an AccessPolicy object (LWS Core §11.3), instead of the other options.')]
  #[CLI\Usage(name: 'drush lws:policy:add alice public --target=https://storage.example/lws/alice/root/public/', description: 'Lets everyone read the container public/ of alice and everything in it.')]
  #[CLI\Usage(name: 'drush lws:policy:add alice https://id.example/bob --action=read --action=create --until=2026-12-31T23:59:59Z', description: 'Lets bob read and create in alice until the end of 2026.')]
  public function addPolicy(
    string $storage,
    ?string $assignee = NULL,
    array $options = [
      'action' => [],
      'target' => [],
      'target-type' => 'StorageResource',
      'until' => NULL,
      'client' => NULL,
      'json' => NULL,
    ],
  ): void {
    $ref = $this->storage($storage);
    if (is_string($options['json'] ?? NULL)) {
      $json = @file_get_contents($options['json']);
      if ($json === FALSE) {
        throw new \InvalidArgumentException(dt('Cannot read @file.', ['@file' => $options['json']]));
      }
      $document = Json::decode($json);
    }
    else {
      $assignee = match ($assignee) {
        NULL => throw new \InvalidArgumentException(dt('Name the assignee, or give --json.')),
        'public' => AccessPolicy::PUBLIC,
        'authenticated' => AccessPolicy::AUTHENTICATED,
        default => $assignee,
      };
      $document = AccessPolicy::document(
        $assignee,
        array_values((array) ($options['action'] ?: ['read'])),
        (string) ($options['target-type'] ?? 'StorageResource'),
        array_values((array) ($options['target'] ?: [$ref->uri . 'root/'])),
        is_string($options['until'] ?? NULL) ? $options['until'] : NULL,
        is_string($options['client'] ?? NULL) ? $options['client'] : NULL,
      );
    }
    $policy = $this->policies->add($ref, $this->parser->parse($document, $ref));
    $this->logger()?->success(dt('Added policy @id to @storage.', ['@id' => $policy->id(), '@storage' => $storage]));
  }

  /**
   * Lists the access policies of a storage.
   *
   * @param string $storage
   *   The storage slug.
   * @param array<string, mixed> $options
   *   The command options.
   */
  #[CLI\Command(name: 'lws:policy:list')]
  #[CLI\Argument(name: 'storage', description: 'The storage slug.')]
  #[CLI\FieldLabels(labels: [
    'id' => 'ID',
    'assignee' => 'Assignee',
    'actions' => 'Actions',
    'target' => 'Target',
    'constraints' => 'Constraints',
    'source' => 'Source',
  ])]
  #[CLI\DefaultTableFields(fields: ['id', 'assignee', 'actions', 'target', 'constraints'])]
  public function listPolicies(string $storage, array $options = ['format' => 'table']): RowsOfFields {
    $rows = [];
    foreach ($this->policies->forStorage($this->storage($storage)->id) as $id => $entity) {
      $policy = $entity->toAccessPolicy();
      $rows[$id] = [
        'id' => $id,
        'assignee' => $policy->assignee,
        'actions' => implode(', ', $policy->actions),
        'target' => substr($policy->targetType, strlen('https://www.w3.org/ns/lws#')) . ': ' . implode(' ', $policy->targetValues),
        'constraints' => Json::encode(array_map(static fn ($constraint) => $constraint->toJson(), $policy->constraints)),
        'source' => $entity->getSource(),
      ];
    }
    return new RowsOfFields($rows);
  }

  /**
   * Deletes an access policy, with immediate effect.
   *
   * @param string $storage
   *   The storage slug.
   * @param int $id
   *   The policy ID.
   */
  #[CLI\Command(name: 'lws:policy:delete')]
  #[CLI\Argument(name: 'storage', description: 'The storage slug.')]
  #[CLI\Argument(name: 'id', description: 'The policy ID, as lws:policy:list shows it.')]
  public function deletePolicy(string $storage, int $id): void {
    $policy = $this->policies->load($this->storage($storage)->id, $id)
      ?? throw new \InvalidArgumentException(dt('@storage has no policy @id.', ['@storage' => $storage, '@id' => $id]));
    $policy->delete();
    $this->logger()?->success(dt('Deleted policy @id of @storage.', ['@id' => $id, '@storage' => $storage]));
  }

  /**
   * A storage by slug.
   *
   * @throws \InvalidArgumentException
   */
  private function storage(string $slug): StorageRef {
    return $this->storages->get($slug) ?? throw new \InvalidArgumentException(dt('There is no storage @slug.', ['@slug' => $slug]));
  }

}
