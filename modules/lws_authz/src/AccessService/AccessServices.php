<?php

declare(strict_types=1);

namespace Drupal\lws_authz\AccessService;

use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws\Storage\StorageServiceInterface;
use Ebremer\Lws\ServiceType;
use Ebremer\Lws\Vocabulary;

/**
 * Advertises a storage's access request and grant services (LWS Core §11.1).
 */
final class AccessServices implements StorageServiceInterface {

  public function __construct(
    private readonly LwsUrlGenerator $urls,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function services(string $storageUri, string $slug): array {
    return [
      [
        'type' => ServiceType::ACCESS_REQUEST,
        'serviceEndpoint' => $this->urls->accessUri($slug, 'requests'),
        'conformsTo' => [Vocabulary::ACCESS_PROFILE],
      ],
      [
        'type' => ServiceType::ACCESS_GRANT,
        'serviceEndpoint' => $this->urls->accessUri($slug, 'grants'),
        'conformsTo' => [Vocabulary::ACCESS_PROFILE],
      ],
    ];
  }

}
