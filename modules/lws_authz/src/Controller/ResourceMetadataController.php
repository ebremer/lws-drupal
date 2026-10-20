<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Controller;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\lws\Http\ProblemResponse;
use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws\Routing\LwsUrlParser;
use Drupal\lws\Storage\StorageRegistryInterface;
use Drupal\lws_authz\AuthorizationServers;
use Ebremer\Lws\Vocabulary;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The protected resource metadata of resources in the LWS URL space.
 *
 * Every 401 names, as its challenge's "resource_metadata", the metadata of
 * the URL the client requested (LWS Core, Authorization Server Discovery):
 * the well-known segment inserted between the host and the path (RFC 9728
 * §3.1). For a site below the root of its host that URL is outside the site,
 * and the web server or a proxy sends it to Drupal's own
 * /.well-known/oauth-protected-resource with the base path removed, as it
 * sends the authorization server's metadata (DESIGN.md §6.2). What follows
 * the well-known segment is then the path Drupal receives for the resource
 * itself.
 *
 * The document (RFC 9728 §2, LWS Core "Protected Resource Metadata") names:
 *
 * - "resource": the URL requested, character for character: the base URL,
 *   the path still percent-encoded, and the query as sent. A client discards
 *   metadata whose resource is not identical to the URL it requested (RFC
 *   9728 §3.3);
 * - "authorization_servers": the issuer of the storage's authorization
 *   server, which LWS Core requires;
 * - "bearer_methods_supported": the Authorization header only.
 *
 * Apart from "resource", which only repeats the request, it is the same for
 * every URL of a storage, of a resource that exists or not, readable or not:
 * nothing stored is looked up, so it reveals nothing. A URL that names no
 * storage, or a storage without an authorization server, has none (404).
 * It needs no token: clients fetch it because theirs was refused.
 */
final class ResourceMetadataController implements ContainerInjectionInterface {

  use AutowireTrait;

  /**
   * How long clients may cache the metadata, in seconds, as the AS metadata.
   */
  private const MAX_AGE = 300;

  public function __construct(
    private readonly LwsUrlParser $parser,
    private readonly LwsUrlGenerator $urls,
    private readonly AuthorizationServers $servers,
    #[Autowire(service: 'Drupal\lws\Storage\StorageRegistryInterface')]
    private readonly ?StorageRegistryInterface $storages = NULL,
  ) {}

  /**
   * Serves the metadata of the URL named by the path after the well-known one.
   */
  public function metadata(Request $request): Response {
    $path = substr($request->getPathInfo(), strlen(Vocabulary::WELL_KNOWN_OAUTH_PROTECTED_RESOURCE));
    $resource = $this->urls->baseUrl() . $path . LwsUrlGenerator::rawQuery($request);
    // Only URLs a refusal can name: those of storages, where a method is
    // allowed. A malformed path is answered 400 before anything else.
    $target = $path === '' ? NULL : $this->parser->parse($path);
    $storage = $target?->storage === NULL || $target->allowedMethods() === [] ? NULL : $this->storages?->get($target->storage);
    $server = $storage === NULL ? NULL : $this->servers->forStorage($storage);
    if ($server === NULL || !mb_check_encoding($resource, 'UTF-8')) {
      return ProblemResponse::create(404, 'No protected resource of this site has this metadata URL.');
    }
    $response = new JsonResponse(NULL, 200, ['Cache-Control' => 'public, max-age=' . self::MAX_AGE]);
    $response->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    return $response->setData([
      'resource' => $resource,
      'authorization_servers' => [$server->getIssuer()],
      // Access tokens are read from the Authorization header only, never from
      // a form body or the query (RFC 6750 §2.2, §2.3).
      'bearer_methods_supported' => ['header'],
    ]);
  }

}
