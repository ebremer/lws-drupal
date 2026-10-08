<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\lws\Access\AccessDecisionInterface;
use Drupal\lws\Access\Action;
use Drupal\lws\Agent\Authentication;
use Drupal\lws\Http\LwsHttpException;
use Drupal\lws\Http\LwsResponse;
use Drupal\lws\Http\MediaTypeNegotiator;
use Drupal\lws\Http\Preconditions;
use Drupal\lws\Routing\LwsTarget;
use Drupal\lws_storage\Entity\LwsResourceInterface;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\Http\ContentResponse;
use Drupal\lws_storage\Listing\ContainerPager;
use Drupal\lws_storage\ResourceLinks;
use Drupal\lws_storage\ResourceRepository;
use Drupal\lws_storage\StorageManager;
use Ebremer\Lws\Http\LinkHeader;
use Ebremer\Lws\LinkRelation;
use Ebremer\Lws\MediaType;
use Ebremer\Lws\ResourceType;
use Ebremer\Lws\Vocabulary;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves containers and data resources (LWS Core §9).
 */
final class ResourceController implements ContainerInjectionInterface {

  use AutowireTrait;

  /**
   * The equivalent media types of a container representation (§12.1.1).
   */
  private const CONTAINER_MEDIA_TYPES = [MediaType::LWS_JSON, MediaType::LD_JSON, MediaType::JSON];

  /**
   * A media type, with any parameters (RFC 9110 §8.3.1).
   */
  private const MEDIA_TYPE = '/^[A-Za-z0-9!#$&^_.+-]+\/[A-Za-z0-9!#$&^_.+-]+(\s*;\s*[A-Za-z0-9!#$&^_.+-]+=("[^"]*"|[A-Za-z0-9!#$&^_.+-]+))*$/';

  public function __construct(
    private readonly ResourceRepository $resources,
    private readonly StorageManager $manager,
    private readonly ResourceLinks $links,
    private readonly ContainerPager $pager,
    private readonly AccessDecisionInterface $decisions,
    private readonly ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'logger.channel.lws')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Serves a GET or HEAD request.
   */
  public function read(LwsStorageInterface $lws_storage, LwsTarget $lws_target, Request $request): Response {
    $resource = $this->resources->findByTarget($lws_storage, $lws_target) ?? throw LwsHttpException::notFound();
    $allow = implode(', ', $lws_target->allowedMethods());
    return $resource->isContainer()
      ? $this->container($lws_storage, $resource, $request, $allow)
      : $this->data($lws_storage, $resource, $request, $allow);
  }

  /**
   * Creates a resource in a container (§9.2).
   *
   * A Link to the Container class with rel="type" asks for a container, and
   * the body is ignored; otherwise the body becomes a data resource.
   */
  public function post(LwsStorageInterface $lws_storage, LwsTarget $lws_target, Request $request): Response {
    $parent = $this->resources->findByTarget($lws_storage, $lws_target) ?? throw LwsHttpException::notFound();
    $container = FALSE;
    foreach (LinkHeader::parse(array_filter($request->headers->all('link'), 'is_string')) as $link) {
      if ($link->hasRel(LinkRelation::TYPE) && $link->href === ResourceType::CONTAINER) {
        $container = TRUE;
      }
    }
    $mediaType = $container ? '' : $this->mediaType($request) ?? 'application/octet-stream';
    $resource = $this->manager->createResource(
      $parent,
      $request->headers->get('Slug'),
      $container,
      $container ? NULL : $this->body($request),
      $mediaType,
      Authentication::fromRequest($request)->agent,
    );
    $this->log('Created', $lws_storage, $resource, $request);

    $uri = $this->links->uri($lws_storage, $resource);
    return LwsResponse::empty(Response::HTTP_CREATED, $this->links->headers($lws_storage, $resource), $resource->getEtag(), ['Location' => $uri]);
  }

  /**
   * Replaces the content of a data resource (§9.4).
   *
   * There is no create-by-PUT: a missing resource is a 404.
   */
  public function put(LwsStorageInterface $lws_storage, LwsTarget $lws_target, Request $request): Response {
    $resource = $this->resources->findByTarget($lws_storage, $lws_target) ?? throw LwsHttpException::notFound();
    $this->requireIfMatch($request);
    $updated = $this->manager->replaceContent(
      $resource,
      $this->body($request),
      $this->mediaType($request),
      fn (LwsResourceInterface $current) => $this->checkPreconditions($request, $current),
    );
    $this->log('Replaced', $lws_storage, $updated, $request);
    return LwsResponse::empty(Response::HTTP_NO_CONTENT, [], $updated->getEtag());
  }

  /**
   * Deletes a resource (§9.5).
   *
   * A container that is not empty needs "Depth: infinity", and then the
   * agent must be allowed to delete every member.
   */
  public function delete(LwsStorageInterface $lws_storage, LwsTarget $lws_target, Request $request): Response {
    $resource = $this->resources->findByTarget($lws_storage, $lws_target) ?? throw LwsHttpException::notFound();
    $depth = $request->headers->get('Depth');
    if ($depth !== NULL && strtolower(trim($depth)) !== 'infinity') {
      throw LwsHttpException::badRequest('The only Depth a delete accepts is "infinity".');
    }
    $this->requireIfMatch($request);
    $agent = Authentication::fromRequest($request)->agent;
    $this->manager->deleteResource(
      $resource,
      $depth !== NULL,
      fn (LwsResourceInterface $current) => $this->checkPreconditions($request, $current),
      fn (LwsResourceInterface $member): bool => $this->decisions->decide($agent, Action::Delete, $this->links->contextOf($lws_storage, $member))->isPermitted(),
    );
    $this->log('Deleted', $lws_storage, $resource, $request);
    return LwsResponse::empty(Response::HTTP_NO_CONTENT);
  }

  /**
   * Serves one page of a container representation (§8.1, §12.1.2).
   *
   * Every listing is presented as paginated: it links its first page, which
   * is the container's own URI, and the next, previous and last pages where
   * there are any. Other pages are reached through opaque cursors.
   */
  private function container(LwsStorageInterface $storage, LwsResourceInterface $container, Request $request, string $allow): Response {
    $cursor = $request->query->has('page') ? (string) $request->query->get('page') : NULL;
    if ($cursor !== NULL) {
      $this->pager->after($container, $cursor);
    }
    $type = MediaTypeNegotiator::negotiate($request->headers->get('Accept'), self::CONTAINER_MEDIA_TYPES)
      ?? throw LwsHttpException::notAcceptable(self::CONTAINER_MEDIA_TYPES);
    $uri = $this->links->uri($storage, $container);
    // The first page keeps the container's own entity tag, which a later
    // conditional DELETE of the container compares with.
    $etag = $container->getEtag() . ($cursor === NULL ? '' : '.' . substr(hash('sha256', $cursor), 0, 12));
    $links = [...$this->links->headers($storage, $container), LinkHeader::format($uri, LinkRelation::FIRST)];
    $headers = ['Vary' => 'Accept', 'Allow' => $allow];
    $lastModified = new \DateTimeImmutable('@' . $container->getChangedTime());

    $status = Preconditions::evaluate($request, $etag, $container->getChangedTime());
    if ($status === 412) {
      throw LwsHttpException::preconditionFailed();
    }
    if ($status === 304) {
      return LwsResponse::empty(Response::HTTP_NOT_MODIFIED, $links, $etag, $headers)->setLastModified($lastModified);
    }

    $agent = Authentication::fromRequest($request)->agent;
    $context = $this->links->contextOf($storage, $container);
    $page = $this->pager->page($storage, $container, $cursor, $this->decisions->forAgent($agent, $context->storage));
    foreach ([LinkRelation::NEXT => $page->next, LinkRelation::PREV => $page->prev, LinkRelation::LAST => $page->last] as $rel => $pageCursor) {
      if ($pageCursor !== NULL) {
        $links[] = LinkHeader::format($pageCursor === '' ? $uri : $uri . '?page=' . $pageCursor, $rel);
      }
    }

    $items = [];
    foreach ($page->members as $member) {
      $item = [
        'type' => $member->isContainer() ? 'Container' : 'DataResource',
        'id' => $this->links->uri($storage, $member),
      ];
      if (!$member->isContainer()) {
        $item['format'] = $member->getMediaType();
        $item['size'] = $member->getSize();
      }
      $item['modified'] = gmdate('Y-m-d\TH:i:s\Z', $member->getChangedTime());
      $items[] = $item;
    }
    $body = [
      '@context' => Vocabulary::LWS_CONTEXT,
      'id' => $uri,
      'type' => 'Container',
      'totalItems' => $page->total,
      'items' => $items,
    ];
    return LwsResponse::json($body, $type->contentType([Vocabulary::LWS_CONTEXT]), $links, $etag, $headers)
      ->setLastModified($lastModified);
  }

  /**
   * Serves a data resource's content, with byte ranges (§9.3, RFC 9110 §14).
   */
  private function data(LwsStorageInterface $storage, LwsResourceInterface $resource, Request $request, string $allow): Response {
    $uri = $resource->getContentFile()?->getFileUri();
    if ($uri === NULL || !file_exists($uri)) {
      throw new \RuntimeException(sprintf('The content of %s is missing.', $this->links->uri($storage, $resource)));
    }
    $headers = [
      'Allow' => $allow,
      // Content is the client's, not the site's: it must never run as a page
      // of this origin.
      'Content-Security-Policy' => 'sandbox',
      'X-Content-Type-Options' => 'nosniff',
    ];
    $notModified = $this->notModified($request, $resource, $this->links->headers($storage, $resource), $headers);
    if ($notModified !== NULL) {
      return $notModified;
    }
    $response = new ContentResponse($uri, Response::HTTP_OK, $headers + [
      'Content-Type' => (string) $resource->getMediaType(),
      'Cache-Control' => LwsResponse::CACHE_CONTROL,
    ], FALSE, NULL, FALSE, FALSE);
    $response->headers->set('Link', $this->links->headers($storage, $resource));
    $response->setEtag($resource->getEtag());
    $response->setLastModified(new \DateTimeImmutable('@' . $resource->getChangedTime()));
    return $response;
  }

  /**
   * The 304 or 412 a conditional read gets, if it gets one.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param \Drupal\lws_storage\Entity\LwsResourceInterface $resource
   *   The resource.
   * @param list<string> $links
   *   Its Link header values.
   * @param array<string, string> $headers
   *   Further headers for a 304.
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   412 when a precondition fails.
   */
  private function notModified(Request $request, LwsResourceInterface $resource, array $links, array $headers): ?Response {
    $status = Preconditions::evaluate($request, $resource->getEtag(), $resource->getChangedTime());
    if ($status === 412) {
      throw LwsHttpException::preconditionFailed();
    }
    if ($status !== 304) {
      return NULL;
    }
    $response = LwsResponse::empty(Response::HTTP_NOT_MODIFIED, $links, $resource->getEtag(), $headers);
    $response->setLastModified(new \DateTimeImmutable('@' . $resource->getChangedTime()));
    return $response;
  }

  /**
   * Checks a write's preconditions against the locked resource.
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   412 when a precondition fails.
   */
  private function checkPreconditions(Request $request, LwsResourceInterface $current): void {
    if (Preconditions::evaluate($request, $current->getEtag(), $current->getChangedTime()) !== NULL) {
      throw LwsHttpException::preconditionFailed();
    }
  }

  /**
   * Refuses unconditional changes where the site requires If-Match.
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   428 without If-Match.
   */
  private function requireIfMatch(Request $request): void {
    if ($this->configFactory->get('lws_storage.settings')->get('require_if_match') && !$request->headers->has('If-Match')) {
      throw LwsHttpException::preconditionRequired();
    }
  }

  /**
   * The media type of the request body, if it has one.
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   400 for a malformed one, 415 for form data.
   */
  private function mediaType(Request $request): ?string {
    $type = $request->headers->get('Content-Type');
    if ($type === NULL || trim($type) === '') {
      return NULL;
    }
    $type = trim($type);
    if (strlen($type) > 255 || preg_match(self::MEDIA_TYPE, $type) !== 1) {
      throw LwsHttpException::badRequest('The Content-Type is not a valid media type.');
    }
    // PHP consumes multipart bodies itself, so their bytes never arrive.
    if (str_starts_with(strtolower($type), 'multipart/form-data')) {
      throw LwsHttpException::unsupportedMediaType('Send the content as the request body, not as form data.');
    }
    return $type;
  }

  /**
   * The request body, as a stream.
   *
   * @return resource
   *   A readable stream.
   */
  private function body(Request $request) {
    $body = $request->getContent(TRUE);
    assert(is_resource($body));
    return $body;
  }

  /**
   * Logs a write: what, by whom and through which client (DESIGN.md §8.5).
   */
  private function log(string $action, LwsStorageInterface $storage, LwsResourceInterface $resource, Request $request): void {
    $agent = Authentication::fromRequest($request)->agent;
    $this->logger->info('@action @uri (@uuid) by @agent with client @client, token @jti.', [
      '@action' => $action,
      '@uri' => $this->links->uri($storage, $resource),
      '@uuid' => $resource->uuid(),
      '@agent' => $agent->subject ?? 'anonymous',
      '@client' => $agent->client ?? '-',
      '@jti' => $agent->tokenId ?? '-',
    ]);
  }

}
