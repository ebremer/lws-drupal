<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Token;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\lws\Outbound\OutboundHttp;
use Drupal\lws\Outbound\OutboundHttpException;
use Drupal\lws_authz\Entity\TrustedAuthorizationServerInterface;
use Ebremer\Lws\Auth\AuthorizationServerMetadata;
use Ebremer\Lws\Exception\ProtocolException;
use Psr\Log\LoggerInterface;

/**
 * The signing keys of trusted authorization servers.
 *
 * Pinned keys come from the server's configuration. Otherwise the keys are
 * discovered as LWS Core §5.2.4.2 requires: the server's metadata at
 * /.well-known/lws-configuration (RFC 8414 §3.1, which puts the well-known
 * segment before any path of the issuer) must name the issuer itself, and its
 * "jwks_uri" gives the keys. Both are fetched through the outbound guard.
 *
 * Discovered keys are cached for an hour, and failures for a minute. A token
 * whose "kid" the cached keys lack may force one refresh per server a minute,
 * which picks up rotated keys without letting anyone make the site fetch at
 * will.
 */
final class AuthorizationServerKeys {

  /**
   * How long discovered keys are cached, in seconds.
   */
  public const TTL = 3600;

  /**
   * How long a failed discovery is remembered, in seconds.
   */
  public const FAILURE_TTL = 60;

  /**
   * The flood control event that limits refreshes.
   */
  public const REFRESH_EVENT = 'lws_authz.jwks_refresh';

  /**
   * The period in which a server's keys are refreshed at most once, in seconds.
   */
  public const REFRESH_WINDOW = 60;

  public function __construct(
    private readonly OutboundHttp $http,
    private readonly CacheBackendInterface $cache,
    private readonly FloodInterface $flood,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * The keys of an authorization server.
   *
   * @param \Drupal\lws_authz\Entity\TrustedAuthorizationServerInterface $server
   *   The server.
   * @param bool $refresh
   *   Whether to fetch discovered keys again, because a token named a key the
   *   cached ones lack. Rate-limited; ignored for pinned keys.
   *
   * @throws \Drupal\lws_authz\Token\KeysUnavailableException
   *   When the keys cannot be obtained.
   */
  public function keySet(TrustedAuthorizationServerInterface $server, bool $refresh = FALSE): JsonWebKeySet {
    $pinned = $server->getJwks();
    if ($pinned !== NULL) {
      try {
        return JsonWebKeySet::parse($pinned);
      }
      catch (\InvalidArgumentException $e) {
        throw new KeysUnavailableException(sprintf('The pinned keys of %s are invalid: %s', $server->id(), $e->getMessage()), 0, $e);
      }
    }

    $issuer = $server->getIssuer();
    $cid = 'jwks:' . hash('sha256', $issuer);
    $cached = $this->cache->get($cid);
    if ($cached !== FALSE && is_array($cached->data)) {
      if (is_string($cached->data['error'] ?? NULL)) {
        throw new KeysUnavailableException($cached->data['error']);
      }
      if (!$refresh || !$this->mayRefresh($issuer)) {
        return JsonWebKeySet::parse($cached->data);
      }
    }

    try {
      $keys = $this->discover($issuer);
    }
    catch (OutboundHttpException | ProtocolException | \InvalidArgumentException $e) {
      $message = sprintf('The keys of the authorization server %s are unavailable: %s', $issuer, $e->getMessage());
      $this->logger->warning($message);
      $this->cache->set($cid, ['error' => $message], $this->time->getRequestTime() + self::FAILURE_TTL);
      throw new KeysUnavailableException($message, 0, $e);
    }
    $this->cache->set($cid, $keys->toArray(), $this->time->getRequestTime() + self::TTL);
    return $keys;
  }

  /**
   * Fetches the keys named by an issuer's metadata.
   *
   * @throws \Drupal\lws\Outbound\OutboundHttpException
   * @throws \Ebremer\Lws\Exception\ProtocolException
   * @throws \InvalidArgumentException
   */
  private function discover(string $issuer): JsonWebKeySet {
    $url = AuthorizationServerMetadata::metadataUrl($issuer);
    $metadata = AuthorizationServerMetadata::parse($this->http->get($url)->json(), $url);
    if ($metadata->issuer !== $issuer) {
      throw new ProtocolException(sprintf('the metadata at %s is for the issuer %s (RFC 8414 §3.3)', $url, $metadata->issuer));
    }
    if ($metadata->jwksUri === NULL) {
      throw new ProtocolException(sprintf('the metadata at %s has no jwks_uri', $url));
    }
    $keys = JsonWebKeySet::parse($this->http->get($metadata->jwksUri, 'application/jwk-set+json, application/json')->json());
    if ($keys->count() === 0) {
      throw new ProtocolException(sprintf('%s has no ES256, ES384 or EdDSA signing keys', $metadata->jwksUri));
    }
    return $keys;
  }

  /**
   * Whether a server's keys may be refreshed now, and if so counts it.
   */
  private function mayRefresh(string $issuer): bool {
    $identifier = hash('sha256', $issuer);
    if (!$this->flood->isAllowed(self::REFRESH_EVENT, 1, self::REFRESH_WINDOW, $identifier)) {
      return FALSE;
    }
    $this->flood->register(self::REFRESH_EVENT, self::REFRESH_WINDOW, $identifier);
    return TRUE;
  }

}
