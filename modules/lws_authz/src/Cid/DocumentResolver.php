<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Cid;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\lws\Outbound\OutboundHttp;
use Drupal\lws\Outbound\OutboundHttpException;
use Psr\Log\LoggerInterface;

/**
 * Dereferences subject identifiers to their controlled identifier documents.
 *
 * A did:key document is derived from the identifier; a did:web document and
 * the document of an HTTPS identifier are fetched through the outbound guard.
 *
 * A credential is presented by a client nobody has authenticated, and names
 * the identifier this site then dereferences. So fetched documents are cached
 * for five minutes, and failures for one, which bounds how often any one URL
 * is fetched; the token endpoint's flood control bounds the rest. The cost is
 * that a key the subject revokes stays usable here until the entry expires.
 */
final class DocumentResolver {

  /**
   * How long a fetched document is reused, in seconds.
   */
  public const TTL = 300;

  /**
   * How long a document that could not be fetched is left alone, in seconds.
   */
  public const FAILURE_TTL = 60;

  /**
   * The media types asked for: CID 1.0's, then JSON-LD and JSON.
   */
  private const ACCEPT = 'application/cid, application/did, application/ld+json, application/json;q=0.9, */*;q=0.1';

  public function __construct(
    private readonly OutboundHttp $http,
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * The document of a subject identifier.
   *
   * @return array<array-key, mixed>
   *   The document, as a JSON object. Its "id" is not checked.
   *
   * @throws \Drupal\lws_authz\Cid\UnresolvableSubjectException
   *   When the identifier cannot be dereferenced.
   */
  public function resolve(string $subject): array {
    if (str_starts_with($subject, 'did:')) {
      $method = Dids::method($subject) ?? throw new UnresolvableSubjectException('The subject is not a valid DID.');
      try {
        return match ($method) {
          'key' => Dids::didKeyDocument($subject),
          'web' => $this->fetch(Dids::didWebUrl($subject)),
          default => throw new UnresolvableSubjectException(sprintf('This server resolves did:%s DIDs only.', implode(' and did:', Dids::METHODS))),
        };
      }
      catch (\InvalidArgumentException $e) {
        throw new UnresolvableSubjectException(sprintf('The subject is not a usable did:%s: %s', $method, $e->getMessage()), 0, $e);
      }
    }
    $scheme = strtolower((string) parse_url($subject, PHP_URL_SCHEME));
    if ($scheme !== 'https' && $scheme !== 'http') {
      throw new UnresolvableSubjectException('The subject is neither an HTTPS URI nor a DID.');
    }
    return $this->fetch(explode('#', $subject, 2)[0]);
  }

  /**
   * Fetches a document, or reuses a cached one.
   *
   * @return array<array-key, mixed>
   *   The document.
   *
   * @throws \Drupal\lws_authz\Cid\UnresolvableSubjectException
   */
  private function fetch(string $url): array {
    $cid = 'cid_document:' . hash('sha256', $url);
    $cached = $this->cache->get($cid);
    if ($cached !== FALSE && is_array($cached->data)) {
      if (isset($cached->data['error'])) {
        throw new UnresolvableSubjectException('The subject\'s controlled identifier document could not be retrieved.');
      }
      return $cached->data['document'];
    }
    try {
      $document = $this->http->get($url, self::ACCEPT)->json();
    }
    catch (OutboundHttpException $e) {
      $this->logger->info('Could not retrieve the controlled identifier document @url: @reason', [
        '@url' => $url,
        '@reason' => $e->getMessage(),
      ]);
      $this->cache->set($cid, ['error' => TRUE], $this->time->getRequestTime() + self::FAILURE_TTL);
      // The reason stays in the log: the client chose the URL, and should not
      // learn how this site's network answers.
      throw new UnresolvableSubjectException('The subject\'s controlled identifier document could not be retrieved.', 0, $e);
    }
    $this->cache->set($cid, ['document' => $document], $this->time->getRequestTime() + self::TTL);
    return $document;
  }

}
