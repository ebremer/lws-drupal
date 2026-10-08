<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Controller;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\lws\Http\LwsHttpException;
use Drupal\lws\Http\LwsResponse;
use Drupal\lws\Http\MediaTypeNegotiator;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\StorageDescriptionBuilder;
use Ebremer\Lws\Http\LinkHeader;
use Ebremer\Lws\LinkRelation;
use Ebremer\Lws\MediaType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves storage descriptions.
 */
final class StorageController implements ContainerInjectionInterface {

  use AutowireTrait;

  /**
   * The media types of a storage description, preferred first (§6.1.2).
   */
  private const MEDIA_TYPES = [MediaType::LWS_CID, MediaType::LD_JSON, MediaType::JSON];

  public function __construct(
    private readonly StorageDescriptionBuilder $descriptions,
  ) {}

  /**
   * Serves the storage description at the storage URI.
   */
  public function description(LwsStorageInterface $lws_storage, Request $request): Response {
    $type = MediaTypeNegotiator::negotiate($request->headers->get('Accept'), self::MEDIA_TYPES)
      ?? throw LwsHttpException::notAcceptable(self::MEDIA_TYPES);
    $description = $this->descriptions->build($lws_storage);
    $contentType = $type->contentType();
    return LwsResponse::json(
      $description,
      $contentType,
      [LinkHeader::format($description['id'], LinkRelation::STORAGE)],
      LwsResponse::etag(json_encode($description, JSON_THROW_ON_ERROR), $contentType),
      ['Vary' => 'Accept'],
    );
  }

}
