<?php

declare(strict_types=1);

namespace Drupal\lws\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\lws\Http\LinkHeader;
use Drupal\lws\Http\LwsHttpException;
use Drupal\lws\Http\LwsResponse;
use Drupal\lws\Lws;
use Drupal\lws\Routing\LwsTarget;
use Drupal\lws\Routing\LwsUrlGenerator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Placeholder responses for the LWS URL space.
 *
 * Every well-formed storage slug answers with a storage description, and
 * every container path with an empty container. No data resources or linksets
 * exist. Step S1 (DESIGN.md §10) replaces this with storages and resources
 * that are stored.
 */
final class UrlSpaceController implements ContainerInjectionInterface {

  public function __construct(
    private readonly LwsUrlGenerator $urls,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('lws.url_generator'));
  }

  /**
   * Serves the storage description (LWS Core §6.1).
   */
  public function description(LwsTarget $lws_target): Response {
    $storage = $this->urls->storageUri((string) $lws_target->storage);
    $body = [
      '@context' => [Lws::CID_CONTEXT, Lws::CONTEXT],
      'id' => $storage,
      'type' => 'Storage',
      'service' => [
        ['type' => 'StorageRoot', 'serviceEndpoint' => $storage . 'root/'],
      ],
    ];
    return LwsResponse::json(
      $body,
      Lws::MEDIA_TYPE_STORAGE_DESCRIPTION,
      [LinkHeader::format($storage, Lws::REL_STORAGE)],
      self::etag($body),
    );
  }

  /**
   * Serves an empty container; no data resources exist yet.
   */
  public function resource(LwsTarget $lws_target): Response {
    if (!$lws_target->container) {
      throw LwsHttpException::notFound();
    }
    $storage = $this->urls->storageUri((string) $lws_target->storage);
    $links = [
      LinkHeader::format($storage, Lws::REL_STORAGE),
      LinkHeader::format(Lws::CONTAINER, 'type'),
    ];
    $parent = $this->urls->parentUri($lws_target);
    if ($parent !== NULL) {
      $links[] = LinkHeader::format($parent, 'up');
    }
    $body = [
      '@context' => Lws::CONTEXT,
      'id' => $this->urls->targetUri($lws_target),
      'type' => 'Container',
      'totalItems' => 0,
      'items' => [],
    ];
    return LwsResponse::json($body, Lws::MEDIA_TYPE_CONTAINER, $links, 'c0');
  }

  /**
   * Answers for linkset resources; none exist yet.
   */
  public function meta(LwsTarget $lws_target): never {
    throw LwsHttpException::notFound();
  }

  /**
   * Answers for paths at which nothing can exist.
   */
  public function unknown(LwsTarget $lws_target): never {
    throw LwsHttpException::forUnaddressable($lws_target);
  }

  /**
   * A strong entity tag for a JSON document.
   *
   * @param array<string, mixed> $body
   *   The document.
   */
  private static function etag(array $body): string {
    $hash = hash('sha256', json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), TRUE);
    return substr(rtrim(strtr(base64_encode($hash), '+/', '-_'), '='), 0, 22);
  }

}
