<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\lws\Access\AccessDecisionInterface;
use Drupal\lws\Access\Action;
use Drupal\lws\Access\ResourceContext;
use Drupal\lws\Agent\Authentication;
use Drupal\lws\Agent\RequestingAgent;
use Drupal\lws\Http\LwsHttpException;
use Drupal\lws\Http\LwsResponse;
use Drupal\lws\Http\RequestBody;
use Drupal\lws\Http\MediaTypeNegotiator;
use Drupal\lws\Http\PaginationCursor;
use Drupal\lws\Routing\LwsTarget;
use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws\Storage\StorageRef;
use Drupal\lws\Storage\StorageRegistryInterface;
use Drupal\lws_authz\AccessService\AccessDeniedException;
use Drupal\lws_authz\AccessService\AccessDocumentParser;
use Drupal\lws_authz\AccessService\AccessRecords;
use Drupal\lws_authz\Entity\LwsAccessRecordInterface;
use Drupal\lws_authz\Policy\InvalidPolicyException;
use Ebremer\Lws\Http\LinkHeader;
use Ebremer\Lws\Json\Json;
use Ebremer\Lws\LinkRelation;
use Ebremer\Lws\MediaType;
use Ebremer\Lws\ResourceType;
use Ebremer\Lws\Vocabulary;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The access request and access grant services of a storage (LWS Core §11).
 *
 * Both are LWS containers whose entries are application/lws+json documents
 * (§11.5):
 *
 * - any authenticated agent may POST a request for itself; only the
 *   storage's controllers may POST a grant, which takes effect at once;
 * - a listing or an entry shows only what the agent may see: everything for
 *   a controller, otherwise the entries it submitted and the grants that name
 *   it (§17.1);
 * - DELETE cancels a request, by its submitter or a controller, or revokes a
 *   grant, by a controller, with immediate effect.
 *
 * Without a valid token, a request is answered with the storage's 401
 * challenge.
 */
final class AccessServiceController implements ContainerInjectionInterface {

  use AutowireTrait;

  /**
   * The media types documents are offered and accepted in.
   */
  private const MEDIA_TYPES = [MediaType::LWS_JSON, MediaType::LD_JSON, MediaType::JSON];

  /**
   * The largest document accepted, in bytes.
   */
  public const MAX_BYTES = 65536;

  /**
   * The entries on one page of a listing.
   */
  public const PAGE_SIZE = 100;

  /**
   * The flood control event of a submitted access request, per agent.
   */
  public const FLOOD_REQUEST = 'lws_authz.access_request';

  public function __construct(
    private readonly AccessRecords $records,
    private readonly AccessDocumentParser $parser,
    private readonly AccessDecisionInterface $decisions,
    private readonly LwsUrlGenerator $urls,
    private readonly PaginationCursor $cursors,
    private readonly FloodInterface $flood,
    private readonly ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'logger.channel.lws_authz')]
    private readonly LoggerInterface $logger,
    #[Autowire(service: 'Drupal\lws\Storage\StorageRegistryInterface')]
    private readonly ?StorageRegistryInterface $storages = NULL,
  ) {}

  /**
   * Serves a GET or HEAD: the listing of a service, or an entry.
   */
  public function read(LwsTarget $lws_target, Request $request): Response {
    [$storage, $agent, $controls] = $this->prepare($lws_target, $request);
    $type = MediaTypeNegotiator::negotiate($request->headers->get('Accept'), self::MEDIA_TYPES)
      ?? throw LwsHttpException::notAcceptable(self::MEDIA_TYPES);
    if ($lws_target->recordId === NULL) {
      return $this->listing($storage, $lws_target, $agent, $controls, $request, $type->contentType([Vocabulary::LWS_CONTEXT]));
    }
    $record = $this->entry($storage, $lws_target, $agent, $controls);
    $document = $record->getDocument();
    $links = [
      LinkHeader::format($storage->uri, LinkRelation::STORAGE),
      LinkHeader::format(ResourceType::DATA_RESOURCE, LinkRelation::TYPE),
      LinkHeader::format($this->urls->accessUri($storage->slug, (string) $lws_target->service), LinkRelation::UP),
    ];
    $headers = ['Vary' => 'Accept', 'Allow' => implode(', ', $lws_target->allowedMethods())];
    // Entries never change: their content identifies them.
    return LwsResponse::json($document, $type->contentType([Vocabulary::LWS_CONTEXT]), $links, LwsResponse::etag(Json::encode($document)), $headers);
  }

  /**
   * Serves a POST: submits an access request, or grants access.
   */
  public function post(LwsTarget $lws_target, Request $request): Response {
    [$storage, $agent, $controls] = $this->prepare($lws_target, $request);
    $grant = $lws_target->service === 'grants';
    if (!$agent->isAuthenticated()) {
      throw LwsHttpException::forbidden('Submitting needs an access token.');
    }
    if ($grant && !$controls) {
      throw LwsHttpException::forbidden('Only the storage\'s controllers may grant access.');
    }
    if (!$grant) {
      $limit = (int) ($this->configFactory->get('lws_authz.settings')->get('rate_limits.access_requests') ?? 30);
      $identifier = hash('sha256', (string) $agent->subject);
      if (!$this->flood->isAllowed(self::FLOOD_REQUEST, $limit, 3600, $identifier)) {
        throw LwsHttpException::tooManyRequests(3600);
      }
      $this->flood->register(self::FLOOD_REQUEST, 3600, $identifier);
    }

    $type = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));
    if (!in_array($type, self::MEDIA_TYPES, TRUE)) {
      throw LwsHttpException::unsupportedMediaType('Send the document as application/lws+json.');
    }
    $body = RequestBody::read($request, self::MAX_BYTES);
    try {
      $json = Json::decode($body);
    }
    catch (\JsonException) {
      throw LwsHttpException::badRequest('The document is not JSON.');
    }
    $kind = $grant ? LwsAccessRecordInterface::GRANT : LwsAccessRecordInterface::REQUEST;
    try {
      $document = $this->parser->parse($json, $kind, $storage);
      $record = $grant ? $this->records->grant($storage, $document, $agent) : $this->records->submitRequest($storage, $document, $agent);
    }
    catch (InvalidPolicyException $e) {
      throw LwsHttpException::unprocessable($e->getMessage());
    }
    catch (AccessDeniedException $e) {
      throw LwsHttpException::forbidden($e->getMessage());
    }
    $uri = $this->records->uri($storage, $record);
    $this->logger->notice('@agent @did at @storage: @uri', [
      '@agent' => $agent->subject,
      '@did' => $grant ? 'granted access' : 'requested access',
      '@storage' => $storage->uri,
      '@uri' => $uri,
    ]);
    $links = [
      LinkHeader::format($storage->uri, LinkRelation::STORAGE),
      LinkHeader::format(ResourceType::DATA_RESOURCE, LinkRelation::TYPE),
    ];
    return LwsResponse::empty(Response::HTTP_CREATED, $links, NULL, ['Location' => $uri]);
  }

  /**
   * Serves a DELETE: cancels a request, or revokes a grant.
   */
  public function delete(LwsTarget $lws_target, Request $request): Response {
    [$storage, $agent, $controls] = $this->prepare($lws_target, $request);
    $record = $this->entry($storage, $lws_target, $agent, $controls);
    $ownRequest = $record->getKind() === LwsAccessRecordInterface::REQUEST && $record->getCreator() === $agent->subject;
    $mayDelete = $controls || $ownRequest;
    if (!$mayDelete) {
      throw LwsHttpException::forbidden($record->getKind() === LwsAccessRecordInterface::GRANT
        ? 'Only the storage\'s controllers may revoke a grant.'
        : 'Only its submitter or the storage\'s controllers may cancel a request.');
    }
    $this->records->delete($storage, $record);
    $this->logger->notice('@agent deleted @uri', [
      '@agent' => $agent->subject,
      '@uri' => $this->urls->targetUri($lws_target),
    ]);
    return LwsResponse::empty(Response::HTTP_NO_CONTENT);
  }

  /**
   * The storage, the agent, and whether the agent controls the storage.
   *
   * @return array{\Drupal\lws\Storage\StorageRef, \Drupal\lws\Agent\RequestingAgent, bool}
   *   The three.
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   404 for a storage that does not exist, 503 for a blocked one, and a
   *   refusal, which becomes the 401 challenge, for a token that was
   *   rejected.
   */
  private function prepare(LwsTarget $target, Request $request): array {
    $storage = $target->storage === NULL ? NULL : $this->storages?->get($target->storage);
    if ($storage === NULL) {
      throw LwsHttpException::notFound();
    }
    if (!$storage->enabled) {
      throw LwsHttpException::serviceUnavailable('This storage is blocked.');
    }
    $authentication = Authentication::fromRequest($request);
    if ($authentication->isFailed()) {
      throw LwsHttpException::forbidden((string) $authentication->errorDescription);
    }
    $agent = $authentication->agent;
    $controls = $this->decisions->decide($agent, Action::Control, new ResourceContext($storage, $storage->uri, [], TRUE))->isPermitted();
    return [$storage, $agent, $controls];
  }

  /**
   * An entry the agent may see.
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   404 if there is none; a refusal if the agent may not see it.
   */
  private function entry(StorageRef $storage, LwsTarget $target, RequestingAgent $agent, bool $controls): LwsAccessRecordInterface {
    $kind = $target->service === 'grants' ? LwsAccessRecordInterface::GRANT : LwsAccessRecordInterface::REQUEST;
    $record = $this->records->load($storage, $kind, (string) $target->recordId) ?? throw LwsHttpException::notFound();
    if (!$controls && !$this->records->visible($storage, $record, $agent)) {
      throw LwsHttpException::forbidden('This is neither yours nor addressed to you.');
    }
    return $record;
  }

  /**
   * One page of a service's listing (§8.1), as for any container.
   */
  private function listing(StorageRef $storage, LwsTarget $target, RequestingAgent $agent, bool $controls, Request $request, string $contentType): Response {
    if (!$agent->isAuthenticated()) {
      throw LwsHttpException::forbidden('Listing needs an access token.');
    }
    $uri = $this->urls->accessUri($storage->slug, (string) $target->service);
    $after = 0;
    if ($request->query->has('page')) {
      $decoded = $this->cursors->decode($uri, (string) $request->query->get('page'));
      if ($decoded === NULL || preg_match('/^[1-9][0-9]*$/', $decoded) !== 1) {
        throw LwsHttpException::notFound('This page of the listing does not exist.');
      }
      $after = (int) $decoded;
    }
    $kind = $target->service === 'grants' ? LwsAccessRecordInterface::GRANT : LwsAccessRecordInterface::REQUEST;
    [$records, $total, $next] = $this->records->page($storage, $kind, $agent, $controls, $after, self::PAGE_SIZE);
    $term = $kind === LwsAccessRecordInterface::GRANT ? ResourceType::ACCESS_GRANT : ResourceType::ACCESS_REQUEST;
    $items = [];
    foreach ($records as $record) {
      $items[] = [
        'type' => ['DataResource', $term],
        'id' => $this->records->uri($storage, $record),
        'format' => MediaType::LWS_JSON,
        'modified' => gmdate('Y-m-d\TH:i:s\Z', (int) $record->get('created')->value),
      ];
    }
    $links = [
      LinkHeader::format($storage->uri, LinkRelation::STORAGE),
      LinkHeader::format(ResourceType::CONTAINER, LinkRelation::TYPE),
      LinkHeader::format($uri, LinkRelation::FIRST),
    ];
    if ($next !== NULL) {
      $links[] = LinkHeader::format($uri . '?page=' . $this->cursors->encode($uri, (string) $next), LinkRelation::NEXT);
    }
    $body = [
      '@context' => Vocabulary::LWS_CONTEXT,
      'id' => $uri,
      'type' => 'Container',
      'totalItems' => $total,
      'items' => $items,
    ];
    $headers = [
      'Vary' => 'Accept',
      'Allow' => implode(', ', $target->allowedMethods()),
      'Accept-Post' => MediaType::LWS_JSON,
    ];
    return LwsResponse::json($body, $contentType, $links, NULL, $headers);
  }

}
