<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Hook;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\lws\Http\RequestBody;
use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws_authz\AuthorizationServers;
use Drupal\lws_storage\Content\ContentStore;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\StorageRegistry;
use Ebremer\Lws\Vocabulary;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Status report entries for LWS storages.
 */
final class LwsStorageRequirements {

  use StringTranslationTrait;

  public function __construct(
    private readonly ContentStore $content,
    private readonly StreamWrapperManagerInterface $streamWrappers,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StorageRegistry $registry,
    private readonly AuthorizationServers $servers,
    private readonly LwsUrlGenerator $urls,
    private readonly ClientInterface $httpClient,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   *
   * @return array<string, array<string, mixed>>
   *   The requirements.
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    $requirements = [
      'lws_storage_private_files' => $this->fileSystem(),
      'lws_storage_upload_size' => $this->uploadSize(),
    ];
    $metadata = $this->resourceMetadata();
    if ($metadata !== NULL) {
      $requirements['lws_storage_resource_metadata'] = $metadata;
    }
    return $requirements;
  }

  /**
   * The entry for the file system that holds content.
   *
   * @return array<string, mixed>
   *   The requirement.
   */
  private function fileSystem(): array {
    $scheme = $this->content->scheme();
    $title = $this->t('LWS resource content');
    if (!$this->content->isAvailable()) {
      return [
        'title' => $title,
        'value' => $this->t('No @scheme file system', ['@scheme' => $scheme]),
        'description' => $scheme === 'private'
          ? $this->t('The content of data resources is kept in the private file system, so data resources cannot be created. Set <code>@setting</code> in settings.php.', ['@setting' => "\$settings['file_private_path']"])
          : $this->t('The stream wrapper that lws_storage.settings:scheme names is not available, so data resources cannot be created.'),
        'severity' => RequirementSeverity::Error,
      ];
    }
    $class = $this->streamWrappers->getClass($scheme);
    if (is_string($class) && is_a($class, PublicStream::class, TRUE)) {
      return [
        'title' => $title,
        'value' => $this->t('In @scheme://lws', ['@scheme' => $scheme]),
        'description' => $this->t('The web server serves the @scheme file system itself, so anyone who learns where a resource\'s content is can download it, whatever LWS policy says. <a href=":url">Keep content</a> in the private file system.', [
          '@scheme' => $scheme,
          ':url' => Url::fromRoute('lws_storage.settings')->toString(),
        ]),
        'severity' => RequirementSeverity::Error,
      ];
    }
    return [
      'title' => $title,
      'value' => $this->t('In @scheme://lws', ['@scheme' => $scheme]),
      'severity' => RequirementSeverity::OK,
    ];
  }

  /**
   * The entry for the size of content one request may write.
   *
   * Content is the request body itself. PHP's post_max_size limits it for
   * POST, which creates, but not for PUT, which replaces; the web server may
   * limit both, as Apache's LimitRequestBody, 1 GB by default, does.
   *
   * @return array<string, mixed>
   *   The requirement.
   */
  private function uploadSize(): array {
    $limit = $this->content->maxBytes();
    $post = RequestBody::postMaxSize();
    $arguments = [
      '@limit' => ByteSizeMarkup::create($limit),
      '@post' => $post > 0 ? ByteSizeMarkup::create($post) : $this->t('unlimited'),
      ':url' => Url::fromRoute('lws_storage.settings')->toString(),
    ];
    $requirement = ['title' => $this->t('LWS content size')];
    if ($limit === 0) {
      return $requirement + [
        'value' => $this->t('No limit'),
        'description' => $this->t('Creating a resource is limited by PHP\'s post_max_size (@post), but replacing one only by the quota and the web server. <a href=":url">Set the largest content</a> one request may write.', $arguments),
        'severity' => RequirementSeverity::Warning,
      ];
    }
    if ($post > 0 && $limit > $post) {
      return $requirement + [
        'value' => $this->t('@limit', $arguments),
        'description' => $this->t('PHP\'s post_max_size is @post, so creating a resource larger than that fails with 413 Content Too Large, though replacing one works. Raise post_max_size, or <a href=":url">lower the largest content</a>.', $arguments),
        'severity' => RequirementSeverity::Warning,
      ];
    }
    return $requirement + [
      'value' => $this->t('@limit', $arguments),
      'description' => $this->t('The web server must accept request bodies this large, too.'),
      'severity' => RequirementSeverity::OK,
    ];
  }

  /**
   * The entry for the protected resource metadata of storages.
   *
   * Every 401 names the metadata of the URL requested, which is at
   * /.well-known/oauth-protected-resource on its host followed by its path
   * (RFC 9728 §3.1): a URL a proxy or the web server may not pass on to
   * Drupal, and one outside the site for a site below the root of its host.
   * The status report asks for that of the first storage, as a client would.
   *
   * @return array<string, mixed>|null
   *   The requirement; NULL if that storage has no authorization server, or
   *   there is no storage.
   */
  private function resourceMetadata(): ?array {
    $entities = $this->entityTypeManager->getStorage('lws_storage');
    $ids = $entities->getQuery()->accessCheck(FALSE)->sort('id')->range(0, 1)->execute();
    $entity = $ids === [] ? NULL : $entities->load(reset($ids));
    $storage = $entity instanceof LwsStorageInterface ? $this->registry->ref($entity) : NULL;
    $issuer = $storage === NULL ? NULL : $this->servers->forStorage($storage)?->getIssuer();
    if ($storage === NULL || $issuer === NULL) {
      return NULL;
    }
    $url = LwsUrlGenerator::resourceMetadataUri($storage->uri);
    $requirement = ['title' => $this->t('LWS protected resource metadata'), 'value' => $url];
    try {
      $response = $this->httpClient->request('GET', $url, [
        'timeout' => 5,
        'http_errors' => FALSE,
        'allow_redirects' => FALSE,
        'headers' => ['Accept' => 'application/json'],
      ]);
      $status = $response->getStatusCode();
      $metadata = $status === 200 ? json_decode((string) $response->getBody(), TRUE) : NULL;
      $problem = match (TRUE) {
        $status !== 200 => $this->t('It answers @status.', ['@status' => $status]),
        !is_array($metadata) => $this->t('It is not JSON.'),
        ($metadata['resource'] ?? NULL) !== $storage->uri => $this->t('It describes another resource: @resource.', ['@resource' => is_string($metadata['resource'] ?? NULL) ? $metadata['resource'] : '-']),
        ($metadata['authorization_servers'][0] ?? NULL) !== $issuer => $this->t('It names another authorization server.'),
        default => NULL,
      };
    }
    catch (GuzzleException $e) {
      $problem = $this->t('It cannot be fetched: @message', ['@message' => $e->getMessage()]);
    }
    if ($problem === NULL) {
      return $requirement + ['severity' => RequirementSeverity::OK];
    }
    // Drupal answers at its own /.well-known/oauth-protected-resource,
    // followed by the path it receives for the resource; for a site below the
    // root of its host that is not where clients ask.
    $asked = LwsUrlGenerator::resourceMetadataUri($this->urls->baseUrl());
    $served = $this->urls->baseUrl() . Vocabulary::WELL_KNOWN_OAUTH_PROTECTED_RESOURCE;
    $description = $served === $asked
      ? $this->t('@problem Clients cannot find where to get access tokens for the storages. Make sure that the web server, and any proxy in front of it, passes @path/… to Drupal.', [
        '@problem' => $problem,
        '@path' => Vocabulary::WELL_KNOWN_OAUTH_PROTECTED_RESOURCE,
      ])
      : $this->t('@problem Clients cannot find where to get access tokens for the storages. Have the web server, or a proxy in front of it, send @asked/… to @served/…, where Drupal answers it.', [
        '@problem' => $problem,
        '@asked' => $asked,
        '@served' => $served,
      ]);
    return $requirement + [
      'description' => $description,
      'severity' => RequirementSeverity::Warning,
    ];
  }

}
