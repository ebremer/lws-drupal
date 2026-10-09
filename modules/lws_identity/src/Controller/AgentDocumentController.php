<?php

declare(strict_types=1);

namespace Drupal\lws_identity\Controller;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\lws\Http\LwsResponse;
use Drupal\lws\Http\MediaTypeNegotiator;
use Drupal\lws\Http\NegotiatedType;
use Drupal\lws\Http\ProblemResponse;
use Drupal\lws_identity\AgentDocuments;
use Drupal\lws_identity\AgentUris;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves an agent's controlled identifier document at its agent URI.
 *
 * Anyone may read it: verifiers dereference the agent URI without
 * credentials. The response is never stored by the page cache, and is
 * revalidated by others (its entity tag), so a key removed from it is gone
 * at once for anyone who asks again.
 */
final class AgentDocumentController implements ContainerInjectionInterface {

  use AutowireTrait;

  /**
   * The media types served: CID 1.0's, then JSON-LD and JSON, the same body.
   *
   * A request that accepts none of them gets the first, rather than a 406:
   * verifiers ask for documents with many Accept headers, and this is the
   * only representation there is.
   */
  public const MEDIA_TYPES = ['application/cid', 'application/ld+json', 'application/json'];

  public function __construct(
    private readonly AgentDocuments $documents,
    private readonly AgentUris $uris,
  ) {}

  /**
   * The document of the agent whose URI ends in a UUID.
   */
  public function document(string $uuid, Request $request): Response {
    $user = $this->documents->agent($uuid);
    if ($user === NULL) {
      return ProblemResponse::create(Response::HTTP_NOT_FOUND, 'No agent of this site has this identifier.', $this->uris->base() . $uuid);
    }
    $type = MediaTypeNegotiator::negotiate($request->headers->get('Accept'), self::MEDIA_TYPES) ?? new NegotiatedType(self::MEDIA_TYPES[0]);
    $document = $this->documents->build($user);
    $contentType = $type->contentType();
    $response = LwsResponse::json(
      $document,
      $contentType,
      [],
      LwsResponse::etag(json_encode($document, JSON_THROW_ON_ERROR), $contentType),
      ['Vary' => 'Accept'],
    );
    $response->isNotModified($request);
    return $response;
  }

}
