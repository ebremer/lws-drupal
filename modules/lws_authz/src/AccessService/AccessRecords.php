<?php

declare(strict_types=1);

namespace Drupal\lws_authz\AccessService;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lws\Access\Action;
use Drupal\lws\Access\ResourceContext;
use Drupal\lws\Agent\RequestingAgent;
use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws\Storage\StorageRef;
use Drupal\lws_authz\Entity\LwsAccessRecordInterface;
use Drupal\lws_authz\Policy\PolicyEvaluator;
use Drupal\lws_authz\Policy\PolicyStore;
use Ebremer\Lws\ResourceType;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Access requests and access grants (LWS Core §11, DESIGN.md §6.6).
 *
 * A grant becomes one access policy per entry of its "access", in the same
 * transaction, and revoking it deletes them with it: the next request is
 * decided without them. A request changes no access until a controller
 * grants it.
 *
 * Who may see one (§17.1): the storage's controllers, the agent who
 * submitted it, and for a grant the agents its policies name, unless a
 * client constraint of every such policy excludes the agent's client.
 */
final class AccessRecords {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccessDocumentParser $parser,
    private readonly PolicyStore $policies,
    private readonly Connection $database,
    private readonly EventDispatcherInterface $events,
    private readonly LwsUrlGenerator $urls,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Stores an access request an agent submitted.
   *
   * @param \Drupal\lws\Storage\StorageRef $storage
   *   The storage.
   * @param \Drupal\lws_authz\AccessService\AccessDocument $document
   *   The request, as the parser read it.
   * @param \Drupal\lws\Agent\RequestingAgent $agent
   *   The agent, who must be every policy's assignee.
   *
   * @throws \Drupal\lws_authz\AccessService\AccessDeniedException
   *   When the request asks access for another agent.
   */
  public function submitRequest(StorageRef $storage, AccessDocument $document, RequestingAgent $agent): LwsAccessRecordInterface {
    if ($document->assignees() !== [(string) $agent->subject]) {
      throw new AccessDeniedException('An agent may request access only for itself: every policy\'s "assignee" must be ' . $agent->subject . '.');
    }
    $record = $this->store($storage, $document, $agent, 0);
    $this->events->dispatch(new AccessRecordEvent($record, $storage, $this->uri($storage, $record)), AccessRecordEvent::CREATED);
    return $record;
  }

  /**
   * Grants access: stores the grant and its policies.
   *
   * @param \Drupal\lws\Storage\StorageRef $storage
   *   The storage.
   * @param \Drupal\lws_authz\AccessService\AccessDocument $document
   *   The grant, as the parser read it.
   * @param \Drupal\lws\Agent\RequestingAgent|null $agent
   *   The controller who submitted it over LWS, if one did.
   * @param int $uid
   *   The Drupal user who made it, as by approving a request; 0 for none.
   * @param \Drupal\lws_authz\Entity\LwsAccessRecordInterface|null $request
   *   The request it answers, if any.
   */
  public function grant(StorageRef $storage, AccessDocument $document, ?RequestingAgent $agent = NULL, int $uid = 0, ?LwsAccessRecordInterface $request = NULL): LwsAccessRecordInterface {
    $transaction = $this->database->startTransaction();
    try {
      $record = $this->store($storage, $document, $agent, $uid);
      foreach ($document->policies as $policy) {
        $this->policies->add($storage, $policy, self::source($record), $uid);
      }
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      $this->storage()->resetCache();
      throw $e;
    }
    unset($transaction);
    $this->events->dispatch(new AccessRecordEvent($record, $storage, $this->uri($storage, $record), $request), AccessRecordEvent::CREATED);
    return $record;
  }

  /**
   * Grants what a request asks for, and settles the request.
   *
   * The grant names the request's inbox, so that the agent hears of it.
   *
   * @param \Drupal\lws\Storage\StorageRef $storage
   *   The storage.
   * @param \Drupal\lws_authz\Entity\LwsAccessRecordInterface $request
   *   The request.
   * @param int $uid
   *   The Drupal user who approves it.
   *
   * @throws \Drupal\lws_authz\Policy\InvalidPolicyException
   *   When the request is no longer valid, as when it names resources by a
   *   base URL that has changed.
   */
  public function approve(StorageRef $storage, LwsAccessRecordInterface $request, int $uid): LwsAccessRecordInterface {
    $submitted = $request->getDocument();
    $grant = [
      '@context' => $submitted['@context'] ?? NULL,
      'type' => [ResourceType::ACCESS_GRANT],
      'storage' => $storage->uri,
      'access' => $submitted['access'] ?? NULL,
    ];
    if ($request->getInbox() !== NULL) {
      $grant['inbox'] = $request->getInbox();
    }
    $document = $this->parser->parse(array_filter($grant, static fn ($value) => $value !== NULL), LwsAccessRecordInterface::GRANT, $storage);
    $record = $this->grant($storage, $document, NULL, $uid, $request);
    $this->delete($storage, $request);
    return $record;
  }

  /**
   * Deletes a request, or revokes a grant with its policies.
   */
  public function delete(StorageRef $storage, LwsAccessRecordInterface $record): void {
    $uri = $this->uri($storage, $record);
    $transaction = $this->database->startTransaction();
    try {
      if ($record->getKind() === LwsAccessRecordInterface::GRANT) {
        $this->policies->deleteBySource(self::source($record));
      }
      $record->delete();
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    unset($transaction);
    $this->events->dispatch(new AccessRecordEvent($record, $storage, $uri), AccessRecordEvent::DELETED);
  }

  /**
   * A request or grant of a storage by UUID.
   */
  public function load(StorageRef $storage, string $kind, string $uuid): ?LwsAccessRecordInterface {
    $records = $this->storage()->loadByProperties(['storage' => $storage->id, 'kind' => $kind, 'uuid' => $uuid]);
    $record = reset($records);
    return $record instanceof LwsAccessRecordInterface && $record->uuid() === $uuid ? $record : NULL;
  }

  /**
   * The grant that made a policy, from the policy's source.
   */
  public function grantOf(StorageRef $storage, string $source): ?LwsAccessRecordInterface {
    return str_starts_with($source, 'grant:') ? $this->load($storage, LwsAccessRecordInterface::GRANT, substr($source, 6)) : NULL;
  }

  /**
   * One page of the requests or grants an agent may see, oldest first.
   *
   * @param \Drupal\lws\Storage\StorageRef $storage
   *   The storage.
   * @param string $kind
   *   The kind: "request" or "grant".
   * @param \Drupal\lws\Agent\RequestingAgent $agent
   *   The agent.
   * @param bool $controls
   *   Whether the agent controls the storage, and sees everything.
   * @param int $after
   *   The ID after which the page starts; 0 for the first.
   * @param int $size
   *   The most entries on the page.
   *
   * @return array{list<\Drupal\lws_authz\Entity\LwsAccessRecordInterface>, int, int|null}
   *   The entries, how many the agent may see in all, and the ID after
   *   which the next page starts, if there is one.
   */
  public function page(StorageRef $storage, string $kind, RequestingAgent $agent, bool $controls, int $after, int $size): array {
    $query = $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('storage', $storage->id)
      ->condition('kind', $kind)
      ->sort('id');
    if (!$controls) {
      if (!$agent->isAuthenticated()) {
        return [[], 0, NULL];
      }
      $query->condition($query->orConditionGroup()
        ->condition('creator', (string) $agent->subject)
        ->condition('assignees', (string) $agent->subject));
    }
    $visible = [];
    foreach (array_chunk(array_values($query->execute()), 100) as $ids) {
      foreach ($this->storage()->loadMultiple($ids) as $record) {
        if ($record instanceof LwsAccessRecordInterface && ($controls || $this->visible($storage, $record, $agent))) {
          $visible[(int) $record->id()] = $record;
        }
      }
    }
    $following = array_values(array_filter($visible, static fn (LwsAccessRecordInterface $record): bool => (int) $record->id() > $after));
    $page = array_slice($following, 0, $size);
    $next = count($following) > $size ? (int) $page[$size - 1]->id() : NULL;
    return [$page, count($visible), $next];
  }

  /**
   * Whether an agent who does not control the storage may see an entry.
   */
  public function visible(StorageRef $storage, LwsAccessRecordInterface $record, RequestingAgent $agent): bool {
    if (!$agent->isAuthenticated()) {
      return FALSE;
    }
    if ($record->getCreator() === $agent->subject) {
      return TRUE;
    }
    if ($record->getKind() !== LwsAccessRecordInterface::GRANT) {
      return FALSE;
    }
    // A grant that names the agent through a client it does not use is
    // withheld from it (§17.1).
    $context = new ResourceContext($storage, $storage->uri, [], TRUE);
    $now = $this->time->getRequestTime();
    foreach ($this->policies->forSource(self::source($record)) as $policy) {
      $clients = array_values(array_filter($policy->constraints, static fn ($constraint): bool => $constraint->leftOperand === 'client'));
      if ($policy->assignee === $agent->subject && PolicyEvaluator::holds($clients, Action::Read, $agent, $context, $now)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The requests of a storage awaiting an answer, oldest first.
   *
   * @return list<\Drupal\lws_authz\Entity\LwsAccessRecordInterface>
   *   The requests.
   *
   * @phpstan-impure
   */
  public function pending(int $storageId): array {
    $ids = $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('storage', $storageId)
      ->condition('kind', LwsAccessRecordInterface::REQUEST)
      ->sort('id')
      ->execute();
    return array_values(array_filter($this->storage()->loadMultiple($ids), static fn ($record): bool => $record instanceof LwsAccessRecordInterface));
  }

  /**
   * Deletes the requests and grants of a storage, as when it is deleted.
   *
   * Its policies go with the storage.
   */
  public function deleteForStorage(int $storageId): void {
    $ids = $this->storage()->getQuery()->accessCheck(FALSE)->condition('storage', $storageId)->execute();
    $this->storage()->delete($this->storage()->loadMultiple($ids));
  }

  /**
   * The URI of a request or grant.
   */
  public function uri(StorageRef $storage, LwsAccessRecordInterface $record): string {
    $service = $record->getKind() === LwsAccessRecordInterface::GRANT ? 'grants' : 'requests';
    return $this->urls->accessUri($storage->slug, $service, (string) $record->uuid());
  }

  /**
   * The source of the policies a grant made.
   */
  public static function source(LwsAccessRecordInterface $grant): string {
    return 'grant:' . $grant->uuid();
  }

  /**
   * Saves a request or grant.
   */
  private function store(StorageRef $storage, AccessDocument $document, ?RequestingAgent $agent, int $uid): LwsAccessRecordInterface {
    $record = $this->storage()->create([
      'kind' => $document->kind,
      'storage' => $storage->id,
      'creator' => $agent?->subject,
      'client' => $agent?->client,
      'assignees' => $document->assignees(),
      'inbox' => $document->inbox,
      'uid' => $uid,
    ]);
    assert($record instanceof LwsAccessRecordInterface);
    $uuid = (string) $record->uuid();
    $service = $document->kind === LwsAccessRecordInterface::GRANT ? 'grants' : 'requests';
    $served = ['id' => $this->urls->accessUri($storage->slug, $service, $uuid)] + $document->document;
    $record->set('document', json_encode($served, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $record->save();
    return $record;
  }

  /**
   * The entity storage of requests and grants.
   */
  private function storage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('lws_access');
  }

}
