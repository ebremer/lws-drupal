<?php

declare(strict_types=1);

namespace Drupal\lws_index;

use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws\Storage\StorageServiceInterface;
use Ebremer\Lws\ServiceType;

/**
 * Advertises a storage's type index and type search services (lws10-index).
 *
 * The relations a search may filter on, besides type, are not advertised,
 * here or anywhere else.
 */
final class IndexServices implements StorageServiceInterface {

  public function __construct(
    private readonly LwsUrlGenerator $urls,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function services(string $storageUri, string $slug): array {
    return [
      [
        'type' => ServiceType::TYPE_INDEX,
        'serviceEndpoint' => $this->urls->typesUri($slug, 'index'),
      ],
      [
        'type' => ServiceType::TYPE_SEARCH,
        'serviceEndpoint' => $this->urls->typesUri($slug, 'search'),
      ],
    ];
  }

}
