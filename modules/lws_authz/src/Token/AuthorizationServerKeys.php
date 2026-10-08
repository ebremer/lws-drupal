<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Token;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\lws\Outbound\OutboundHttp;
use Drupal\lws\Outbound\OutboundHttpException;
use Drupal\lws_authz\AuthorizationServerInterface;
use Ebremer\Lws\Auth\AuthorizationServerMetadata;
use Ebremer\Lws\Exception\ProtocolException;
use Psr\Log\LoggerInterface;

/**
 * The signing keys of authorization servers and OpenID Providers.
 *
 * Pinned keys come from the server's configuration, and this site's own
 * server gives its keys the same way. Otherwise the keys are
 * discovered as LWS Core §5.2.4.2 requires: the server's metadata at
 * /.well-known/lws-configuration (RFC 8414 §3.1, which puts the well-known
 * segment before any path of the issuer) must name the issuer itself, and its
 * "jwks_uri" gives the keys. An OpenID Provider's come from its OpenID
 * Connect Discovery document instead, at /.well-known/openid-configuration
 * after the issuer (OpenID Connect Discovery 1.0 §4), which must name the
 * issuer exactly (§4.3). All are fetched through the outbound guard.
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
   * @param \Drupal\lws_authz\AuthorizationServerInterface $server
   *   The server.
   * @param bool $refresh
   *   Whether to fetch discovered keys again, because a token named a key the
   *   cached ones lack. Rate-limited; ignored for pinned keys.
   *
   * @throws \Drupal\lws_authz\Token\KeysUnavailableException
   *   When the keys cannot be obtained.
   */
  public function keySet(AuthorizationServerInterface $server, bool $refresh = FALSE): JsonWebKeySet {
    $pinned = $server->getJwks();
    if ($pinned !== NULL) {
      return self::pinned($pinned, (string) $server->id());
    }
    $issuer = $server->getIssuer();
    return $this->cached('jwks:' . hash('sha256', $issuer), 'authorization server', $issuer, fn (): JsonWebKeySet => $this->discover($issuer), $refresh);
  }

  /**
   * The keys of an OpenID Provider.
   *
   * @param string $issuer
   *   Its issuer identifier, as an ID Token's "iss" gives it.
   * @param string|null $pinned
   *   A pinned JSON Web Key Set, which is used instead of discovery.
   * @param bool $refresh
   *   Whether to fetch discovered keys again, because a token named a key the
   *   cached ones lack. Rate-limited; ignored for pinned keys.
   *
   * @throws \Drupal\lws_authz\Token\KeysUnavailableException
   *   When the keys cannot be obtained.
   */
  public function openIdProviderKeys(string $issuer, ?string $pinned = NULL, bool $refresh = FALSE): JsonWebKeySet {
    if ($pinned !== NULL) {
      return self::pinned($pinned, $issuer);
    }
    return $this->cached('jwks:openid:' . hash('sha256', $issuer), 'OpenID Provider', $issuer, fn (): JsonWebKeySet => $this->discoverOpenId($issuer), $refresh);
  }

  /**
   * Keys from a pinned key set.
   *
   * @throws \Drupal\lws_authz\Token\KeysUnavailableException
   *   When the set is invalid.
   */
  private static function pinned(string $jwks, string $owner): JsonWebKeySet {
    try {
      return JsonWebKeySet::parse($jwks);
    }
    catch (\InvalidArgumentException $e) {
      throw new KeysUnavailableException(sprintf('The pinned keys of %s are invalid: %s', $owner, $e->getMessage()), 0, $e);
    }
  }

  /**
   * Discovered keys, from the cache unless they must be fetched.
   *
   * @param string $cid
   *   The cache ID.
   * @param string $kind
   *   What the issuer is, for messages.
   * @param string $issuer
   *   The issuer.
   * @param \Closure(): \Drupal\lws_authz\Token\JsonWebKeySet $discover
   *   Fetches the keys.
   * @param bool $refresh
   *   Whether to fetch them again if the rate limit allows.
   *
   * @throws \Drupal\lws_authz\Token\KeysUnavailableException
   *   When the keys cannot be obtained, now or a minute ago.
   */
  private function cached(string $cid, string $kind, string $issuer, \Closure $discover, bool $refresh): JsonWebKeySet {
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
      $keys = $discover();
    }
    catch (OutboundHttpException | ProtocolException | \InvalidArgumentException $e) {
      $message = sprintf('The keys of the %s %s are unavailable: %s', $kind, $issuer, $e->getMessage());
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
    return $this->fetchKeys($metadata->jwksUri);
  }

  /**
   * Fetches an OpenID Provider's keys through its discovery document.
   *
   * @throws \Drupal\lws\Outbound\OutboundHttpException
   * @throws \Ebremer\Lws\Exception\ProtocolException
   * @throws \InvalidArgumentException
   */
  private function discoverOpenId(string $issuer): JsonWebKeySet {
    $url = rtrim($issuer, '/') . '/.well-known/openid-configuration';
    $metadata = $this->http->get($url)->json();
    if (($metadata['issuer'] ?? NULL) !== $issuer) {
      throw new ProtocolException(sprintf('the discovery document at %s is not for the issuer %s (OpenID Connect Discovery 1.0 §4.3)', $url, $issuer));
    }
    $jwksUri = $metadata['jwks_uri'] ?? NULL;
    if (!is_string($jwksUri) || $jwksUri === '') {
      throw new ProtocolException(sprintf('the discovery document at %s has no jwks_uri', $url));
    }
    return $this->fetchKeys($jwksUri);
  }

  /**
   * Fetches a key set.
   *
   * @throws \Drupal\lws\Outbound\OutboundHttpException
   * @throws \Ebremer\Lws\Exception\ProtocolException
   * @throws \InvalidArgumentException
   */
  private function fetchKeys(string $jwksUri): JsonWebKeySet {
    $keys = JsonWebKeySet::parse($this->http->get($jwksUri, 'application/jwk-set+json, application/json')->json());
    if ($keys->count() === 0) {
      throw new ProtocolException(sprintf('%s has no signing keys of a kind this site verifies', $jwksUri));
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
