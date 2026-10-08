<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Controller;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\lws\Http\LwsHttpException;
use Drupal\lws\Http\LwsResponse;
use Drupal\lws\Http\MediaTypeNegotiator;
use Drupal\lws\Http\Preconditions;
use Drupal\lws\Routing\LwsTarget;
use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\ResourceLinks;
use Drupal\lws_storage\ResourceRepository;
use Ebremer\Lws\Http\LinkHeader;
use Ebremer\Lws\LinkRelation;
use Ebremer\Lws\MediaType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the linksets of resources (LWS Core §9.1, RFC 9264).
 *
 * Read-only until step S4, and holding only the server-managed relations: the
 * parent container and the LWS class.
 */
final class LinksetController implements ContainerInjectionInterface {

  use AutowireTrait;

  /**
   * The media types of a linkset, preferred first.
   */
  private const MEDIA_TYPES = [MediaType::LINKSET_JSON, MediaType::JSON];

  public function __construct(
    private readonly ResourceRepository $resources,
    private readonly ResourceLinks $links,
    private readonly LwsUrlGenerator $urls,
  ) {}

  /**
   * Serves a GET or HEAD request for a linkset.
   */
  public function read(LwsStorageInterface $lws_storage, LwsTarget $lws_target, Request $request): Response {
    $resource = $this->resources->findByUuid($lws_storage, (string) $lws_target->metaId) ?? throw LwsHttpException::notFound();
    $type = MediaTypeNegotiator::negotiate($request->headers->get('Accept'), self::MEDIA_TYPES)
      ?? throw LwsHttpException::notAcceptable(self::MEDIA_TYPES);

    $context = ['anchor' => $this->links->uri($lws_storage, $resource)];
    $parent = $resource->getParent();
    if ($parent !== NULL) {
      $context[LinkRelation::UP] = [['href' => $this->links->uri($lws_storage, $parent)]];
    }
    $context[LinkRelation::TYPE] = [['href' => $this->links->type($resource)]];
    $body = ['linkset' => [$context]];

    $contentType = $type->contentType();
    $etag = LwsResponse::etag(json_encode($body, JSON_THROW_ON_ERROR), $contentType);
    $headers = [
      'Vary' => 'Accept',
      'Allow' => implode(', ', $lws_target->allowedMethods()),
    ];
    $links = [LinkHeader::format($this->urls->storageUri($lws_storage->getSlug()), LinkRelation::STORAGE)];
    $status = Preconditions::evaluate($request, $etag);
    if ($status === 412) {
      throw LwsHttpException::preconditionFailed();
    }
    if ($status === 304) {
      return LwsResponse::empty(Response::HTTP_NOT_MODIFIED, $links, $etag, $headers);
    }
    return LwsResponse::json($body, $contentType, $links, $etag, $headers);
  }

}
