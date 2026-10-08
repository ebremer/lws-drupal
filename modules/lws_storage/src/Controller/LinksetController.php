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
use Drupal\lws_storage\Entity\LwsResourceInterface;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\Http\JsonPatches;
use Drupal\lws_storage\Linkset\Linksets;
use Drupal\lws_storage\ResourceRepository;
use Drupal\lws_storage\StorageManager;
use Ebremer\Lws\Http\LinkHeader;
use Ebremer\Lws\Json\Json;
use Ebremer\Lws\Json\JsonPatchException;
use Ebremer\Lws\LinkRelation;
use Ebremer\Lws\MediaType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the linksets of resources (LWS Core §9.1, RFC 9264).
 *
 * A linkset holds the server-managed relations of its resource (up, and the
 * LWS class among the types) and the relations clients manage. PUT replaces
 * the latter with a linkset document; PATCH applies a JSON Patch to the
 * document a GET returns. Neither may change a server-managed relation.
 */
final class LinksetController implements ContainerInjectionInterface {

  use AutowireTrait;

  /**
   * The media types of a linkset, preferred first.
   */
  private const MEDIA_TYPES = [MediaType::LINKSET_JSON, MediaType::JSON];

  public function __construct(
    private readonly ResourceRepository $resources,
    private readonly Linksets $linksets,
    private readonly StorageManager $manager,
    private readonly LwsUrlGenerator $urls,
  ) {}

  /**
   * Serves a GET or HEAD request for a linkset.
   */
  public function read(LwsStorageInterface $lws_storage, LwsTarget $lws_target, Request $request): Response {
    $resource = $this->resource($lws_storage, $lws_target);
    $type = MediaTypeNegotiator::negotiate($request->headers->get('Accept'), self::MEDIA_TYPES)
      ?? throw LwsHttpException::notAcceptable(self::MEDIA_TYPES);
    $document = $this->linksets->document($lws_storage, $resource);
    $etag = Linksets::etag($document);
    $headers = $this->headers($lws_target) + ['Vary' => 'Accept'];
    $links = $this->links($lws_storage);
    $lastModified = new \DateTimeImmutable('@' . $resource->getMetadataChangedTime());
    $status = Preconditions::evaluate($request, $etag, $resource->getMetadataChangedTime());
    if ($status === 412) {
      throw LwsHttpException::preconditionFailed();
    }
    if ($status === 304) {
      return LwsResponse::empty(Response::HTTP_NOT_MODIFIED, $links, $etag, $headers)->setLastModified($lastModified);
    }
    return LwsResponse::json($document, $type->contentType(), $links, $etag, $headers)->setLastModified($lastModified);
  }

  /**
   * Replaces the links clients manage with a linkset document.
   *
   * The document may leave out the server-managed relations; if it has them,
   * they must be as they are.
   */
  public function put(LwsStorageInterface $lws_storage, LwsTarget $lws_target, Request $request): Response {
    $resource = $this->resource($lws_storage, $lws_target);
    $type = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));
    if (!in_array($type, self::MEDIA_TYPES, TRUE)) {
      throw LwsHttpException::unsupportedMediaType('Send a linkset document, as application/linkset+json.');
    }
    $body = (string) $request->getContent();
    if (strlen($body) > JsonPatches::MAX_BYTES) {
      throw LwsHttpException::contentTooLarge(JsonPatches::MAX_BYTES);
    }
    try {
      $document = Json::decode($body);
    }
    catch (\JsonException) {
      throw LwsHttpException::badRequest('The linkset document is not JSON.');
    }
    $updated = $this->manager->changeMetadata(
      $resource,
      fn (LwsResourceInterface $current) => $this->linksets->fromDocument($document, $lws_storage, $current, FALSE),
      fn (LwsResourceInterface $current) => $this->checkPreconditions($request, $lws_storage, $current),
    );
    return LwsResponse::empty(Response::HTTP_NO_CONTENT, [], Linksets::etag($this->linksets->document($lws_storage, $updated)));
  }

  /**
   * Applies a JSON Patch to the document a GET returns (§9.1).
   *
   * The result must still be a linkset of the resource (422), with its
   * server-managed relations as they are (409).
   */
  public function patch(LwsStorageInterface $lws_storage, LwsTarget $lws_target, Request $request): Response {
    $resource = $this->resource($lws_storage, $lws_target);
    $patch = JsonPatches::fromRequest($request);
    $updated = $this->manager->changeMetadata(
      $resource,
      function (LwsResourceInterface $current) use ($patch, $lws_storage) {
        // As the client saw it: decoded the way a client decodes it.
        $document = Json::decode(Json::encode($this->linksets->document($lws_storage, $current)));
        try {
          $patched = $patch->apply($document);
        }
        catch (JsonPatchException $e) {
          throw LwsHttpException::conflict($e->getMessage());
        }
        return $this->linksets->fromDocument($patched, $lws_storage, $current, TRUE);
      },
      fn (LwsResourceInterface $current) => $this->checkPreconditions($request, $lws_storage, $current),
    );
    return LwsResponse::empty(Response::HTTP_NO_CONTENT, [], Linksets::etag($this->linksets->document($lws_storage, $updated)));
  }

  /**
   * The resource a linkset URL describes.
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   404 if there is none.
   */
  private function resource(LwsStorageInterface $storage, LwsTarget $target): LwsResourceInterface {
    return $this->resources->findByUuid($storage, (string) $target->metaId) ?? throw LwsHttpException::notFound();
  }

  /**
   * Checks a write's preconditions against the linkset of the locked resource.
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   412 when a precondition fails.
   */
  private function checkPreconditions(Request $request, LwsStorageInterface $storage, LwsResourceInterface $current): void {
    $etag = Linksets::etag($this->linksets->document($storage, $current));
    if (Preconditions::evaluate($request, $etag, $current->getMetadataChangedTime()) !== NULL) {
      throw LwsHttpException::preconditionFailed();
    }
  }

  /**
   * The headers saying how a linkset may be changed (§9.1).
   *
   * @return array<string, string>
   *   Allow and Accept-Patch.
   */
  private function headers(LwsTarget $target): array {
    return [
      'Allow' => implode(', ', $target->allowedMethods()),
      'Accept-Patch' => implode(', ', JsonPatches::ACCEPTED),
    ];
  }

  /**
   * The Link headers of a linkset: its storage.
   *
   * @return list<string>
   *   Link header values.
   */
  private function links(LwsStorageInterface $storage): array {
    return [LinkHeader::format($this->urls->storageUri($storage->getSlug()), LinkRelation::STORAGE)];
  }

}
