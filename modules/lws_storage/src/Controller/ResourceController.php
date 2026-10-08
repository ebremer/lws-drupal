<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Controller;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\lws\Http\LwsHttpException;
use Drupal\lws\Http\LwsResponse;
use Drupal\lws\Http\MediaTypeNegotiator;
use Drupal\lws\Routing\LwsTarget;
use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws_storage\Entity\LwsResourceInterface;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\ResourceRepository;
use Ebremer\Lws\Http\LinkHeader;
use Ebremer\Lws\LinkRelation;
use Ebremer\Lws\MediaType;
use Ebremer\Lws\ResourceType;
use Ebremer\Lws\Vocabulary;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves containers and data resources.
 */
final class ResourceController implements ContainerInjectionInterface {

  use AutowireTrait;

  /**
   * The equivalent media types of a container representation (§12.1.1).
   */
  private const CONTAINER_MEDIA_TYPES = [MediaType::LWS_JSON, MediaType::LD_JSON, MediaType::JSON];

  public function __construct(
    private readonly ResourceRepository $resources,
    private readonly LwsUrlGenerator $urls,
  ) {}

  /**
   * Serves a GET or HEAD request for a resource.
   */
  public function read(LwsStorageInterface $lws_storage, LwsTarget $lws_target, Request $request): Response {
    $resource = $this->resources->findByTarget($lws_storage, $lws_target);
    // Only containers are stored so far; data resources arrive with step S2.
    if ($resource === NULL || !$resource->isContainer()) {
      throw LwsHttpException::notFound();
    }
    return $this->container($lws_storage, $resource, $request);
  }

  /**
   * Serves a container representation (LWS Core §8.1).
   */
  private function container(LwsStorageInterface $storage, LwsResourceInterface $container, Request $request): Response {
    $type = MediaTypeNegotiator::negotiate($request->headers->get('Accept'), self::CONTAINER_MEDIA_TYPES)
      ?? throw LwsHttpException::notAcceptable(self::CONTAINER_MEDIA_TYPES);
    $slug = $storage->getSlug();

    $items = [];
    foreach ($this->resources->children($container) as $member) {
      $items[] = [
        'type' => $member->isContainer() ? 'Container' : 'DataResource',
        'id' => $this->urls->resourceUri($slug, $member->getSegments(), $member->isContainer()),
        'modified' => gmdate('Y-m-d\TH:i:s\Z', $member->getChangedTime()),
      ];
    }
    $body = [
      '@context' => Vocabulary::LWS_CONTEXT,
      'id' => $this->urls->resourceUri($slug, $container->getSegments(), TRUE),
      'type' => 'Container',
      'totalItems' => count($items),
      'items' => $items,
    ];

    $links = [
      LinkHeader::format($this->urls->storageUri($slug), LinkRelation::STORAGE),
      LinkHeader::format(ResourceType::CONTAINER, LinkRelation::TYPE),
    ];
    $parent = $container->getParent();
    if ($parent !== NULL) {
      $links[] = LinkHeader::format($this->urls->resourceUri($slug, $parent->getSegments(), TRUE), LinkRelation::UP);
    }

    $response = LwsResponse::json(
      $body,
      $type->contentType([Vocabulary::LWS_CONTEXT]),
      $links,
      'c' . $container->getVersion(),
      ['Vary' => 'Accept'],
    );
    $response->setLastModified(new \DateTimeImmutable('@' . $container->getChangedTime()));
    return $response;
  }

}
