<?php

declare(strict_types=1);

namespace Drupal\lws_index\Controller;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\lws\Access\AccessDecisionInterface;
use Drupal\lws\Access\AgentAccessScopeInterface;
use Drupal\lws\Agent\Authentication;
use Drupal\lws\Http\LwsHttpException;
use Drupal\lws\Http\LwsResponse;
use Drupal\lws\Http\MediaTypeNegotiator;
use Drupal\lws\Http\NegotiatedType;
use Drupal\lws\Http\RequestBody;
use Drupal\lws\Routing\LwsTarget;
use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws_index\Cursors;
use Drupal\lws_index\Query\FilterParser;
use Drupal\lws_index\Query\TypeFilter;
use Drupal\lws_index\TypeIndex;
use Drupal\lws_index\TypeSearch;
use Drupal\lws_storage\Entity\LwsResourceInterface;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\Listing\ContainerPager;
use Drupal\lws_storage\ResourceLinks;
use Drupal\lws_storage\StorageRegistry;
use Ebremer\Lws\Http\LinkHeader;
use Ebremer\Lws\LinkRelation;
use Ebremer\Lws\MediaType;
use Ebremer\Lws\ResourceType;
use Ebremer\Lws\Vocabulary;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The type index and type search services of a storage (lws10-index).
 *
 * - GET {storage}/types/index lists the types of the resources the agent
 *   may read, a page at a time;
 * - QUERY {storage}/types/search, with an application/lws-query+json filter,
 *   answers the first page of the resources that match it and that the
 *   agent may read, as a ContainerPage; GET of its page links answers the
 *   others.
 *
 * Anyone may ask, without a token too: the answers are worked out for the
 * agent, at the time of the request, and hold nothing it may not read.
 */
final class IndexController implements ContainerInjectionInterface {

  use AutowireTrait;

  /**
   * The media types the answers are offered in, as container listings are.
   */
  private const MEDIA_TYPES = [MediaType::LWS_JSON, MediaType::LD_JSON, MediaType::JSON];

  /**
   * The largest filter accepted, in bytes.
   */
  public const MAX_BYTES = 65536;

  public function __construct(
    private readonly TypeSearch $search,
    private readonly TypeIndex $index,
    private readonly Cursors $cursors,
    private readonly ResourceLinks $links,
    private readonly ContainerPager $pager,
    private readonly AccessDecisionInterface $decisions,
    private readonly StorageRegistry $storages,
    private readonly LwsUrlGenerator $urls,
  ) {}

  /**
   * Serves a GET or HEAD request: a page of the type index or of results.
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   404 for a page link that is not one of this storage's.
   */
  public function read(LwsStorageInterface $lws_storage, LwsTarget $lws_target, Request $request): Response {
    $cursor = $request->query->has('page') ? (string) $request->query->get('page') : NULL;
    if ($lws_target->service === 'search') {
      if ($cursor === NULL) {
        throw LwsHttpException::badRequest('A search is a QUERY request with a filter in its body. The pages of its results are read from the links of its response.');
      }
      [$filter, $after] = $this->cursors->decodeSearch($lws_storage, $cursor)
        ?? throw LwsHttpException::notFound('This page of search results does not exist. Send the search again.');
      return $this->results($lws_storage, $filter, $after, $request, $this->negotiate($request));
    }
    $from = $cursor === NULL ? TypeIndex::FIRST : $this->cursors->decodeTypes($lws_storage, $cursor)
      ?? throw LwsHttpException::notFound('This page of the type index does not exist.');
    return $this->types($lws_storage, $from, $request, $this->negotiate($request));
  }

  /**
   * Serves a QUERY request: a search (RFC 10008).
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   400 without a Content-Type, or for a body that is not a filter; 415 for
   *   a query format other than application/lws-query+json; 406 when the
   *   answer can be in no format the client accepts; 413 for a body over
   *   MAX_BYTES; 422 for a filter more complex than this server supports.
   */
  public function query(LwsStorageInterface $lws_storage, LwsTarget $lws_target, Request $request): Response {
    $formats = $lws_target->queryFormats();
    $contentType = trim((string) $request->headers->get('Content-Type'));
    if ($contentType === '') {
      throw LwsHttpException::badRequest('A QUERY request names the format of its query in Content-Type.');
    }
    if (!in_array(strtolower(trim(explode(';', $contentType)[0])), $formats, TRUE)) {
      throw LwsHttpException::unsupportedQueryFormat($formats);
    }
    $type = $this->negotiate($request);
    $filter = FilterParser::parse(RequestBody::read($request, self::MAX_BYTES));
    return $this->results($lws_storage, $filter, 0, $request, $type);
  }

  /**
   * A page of a search's results, as a ContainerPage.
   *
   * The page links carry the filter. The page has no id: it is a result
   * set, not a container (lws10-index).
   */
  private function results(LwsStorageInterface $storage, TypeFilter $filter, int $after, Request $request, NegotiatedType $type): Response {
    $page = $this->search->page($storage, $filter, $after, $this->scope($storage, $request));
    $this->pager->preloadFiles($page->resources);
    $endpoint = $this->urls->typesUri($storage->getSlug(), 'search');
    $links = [LinkHeader::format($endpoint . '?page=' . $this->cursors->search($storage, $filter, 0), LinkRelation::FIRST)];
    if ($page->next !== NULL) {
      $links[] = LinkHeader::format($endpoint . '?page=' . $this->cursors->search($storage, $filter, $page->next), LinkRelation::NEXT);
    }
    $body = [
      '@context' => Vocabulary::LWS_CONTEXT,
      'type' => ResourceType::CONTAINER_PAGE,
      'totalItems' => $page->total,
      'items' => array_map(fn (LwsResourceInterface $resource): array => $this->links->describe($storage, $resource), $page->resources),
    ];
    return LwsResponse::json($body, $type->contentType([Vocabulary::LWS_CONTEXT]), $links, NULL, self::headers());
  }

  /**
   * A page of the type index.
   *
   * @param \Drupal\lws_storage\Entity\LwsStorageInterface $storage
   *   The storage.
   * @param array{a: string|null, t: string|null, r: int} $from
   *   Where the page starts.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param \Drupal\lws\Http\NegotiatedType $type
   *   The media type of the answer.
   */
  private function types(LwsStorageInterface $storage, array $from, Request $request, NegotiatedType $type): Response {
    $page = $this->index->page($storage, $from, $this->scope($storage, $request));
    $endpoint = $this->urls->typesUri($storage->getSlug(), 'index');
    $links = [LinkHeader::format($endpoint, LinkRelation::FIRST)];
    if ($page->next !== NULL) {
      $links[] = LinkHeader::format($endpoint . '?page=' . $this->cursors->types($storage, $page->next), LinkRelation::NEXT);
    }
    $body = [
      '@context' => Vocabulary::LWS_CONTEXT,
      'type' => ResourceType::TYPE_INDEX,
      'totalItems' => $page->total,
      'items' => array_map(static fn (string $type): array => ['id' => $type], $page->types),
    ];
    return LwsResponse::json($body, $type->contentType([Vocabulary::LWS_CONTEXT]), $links, NULL, self::headers());
  }

  /**
   * What the requesting agent may read in the storage.
   */
  private function scope(LwsStorageInterface $storage, Request $request): AgentAccessScopeInterface {
    return $this->decisions->forAgent(Authentication::fromRequest($request)->agent, $this->storages->ref($storage));
  }

  /**
   * The media type of the answer.
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   406 when the client accepts none of MEDIA_TYPES.
   */
  private function negotiate(Request $request): NegotiatedType {
    return MediaTypeNegotiator::negotiate($request->headers->get('Accept'), self::MEDIA_TYPES)
      ?? throw LwsHttpException::notAcceptable(self::MEDIA_TYPES);
  }

  /**
   * The headers of every answer.
   *
   * It depends on Accept, and on the agent's token: LwsResponse marks it
   * private, so no shared cache gives it to another client.
   *
   * @return array<string, string>
   *   The headers.
   */
  private static function headers(): array {
    return ['Vary' => 'Accept, Authorization'];
  }

}
