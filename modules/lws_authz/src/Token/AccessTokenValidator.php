<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Token;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\lws\Agent\RequestingAgent;
use Drupal\lws_authz\AuthorizationServerInterface;
use Ebremer\Lws\Auth\Jwt;
use Psr\Log\LoggerInterface;

/**
 * Validates access tokens presented to a storage (LWS Core §5.2.4.2).
 *
 * An access token is an RFC 9068 JWT. It is accepted only if:
 *
 * - its header has "typ" at+jwt and an asymmetric "alg" the key set can
 *   verify (never "none" or HMAC), and no "crit";
 * - its signature verifies with a key of the storage's authorization server;
 * - its "iss" is that server;
 * - its "aud" has exactly one value, the storage URI;
 * - it is not expired, not before its "nbf", and not issued in the future,
 *   within the configured clock skew;
 * - it has the claims §5.2.3.2 requires: "sub" and "client_id" as URIs, and
 *   "jti".
 */
final class AccessTokenValidator {

  /**
   * The signature algorithms accepted: never "none", nor an HMAC.
   */
  public const ALGORITHMS = ['ES256', 'ES384', 'EdDSA', 'RS256', 'RS384', 'RS512', 'PS256', 'PS384', 'PS512'];

  /**
   * The "typ" values of an RFC 9068 access token, lower-cased.
   */
  private const TYPES = ['at+jwt', 'application/at+jwt'];

  public function __construct(
    private readonly AuthorizationServerKeys $keys,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Validates a token for a storage.
   *
   * @param string $token
   *   The token, as presented.
   * @param \Drupal\lws_authz\AuthorizationServerInterface $server
   *   The authorization server the storage trusts.
   * @param string $audience
   *   The storage URI.
   *
   * @return \Drupal\lws\Agent\RequestingAgent
   *   The agent the token identifies.
   *
   * @throws \Drupal\lws_authz\Token\InvalidTokenException
   *   When the token must be rejected.
   */
  public function validate(string $token, AuthorizationServerInterface $server, string $audience): RequestingAgent {
    if (substr_count($token, '.') !== 2) {
      throw new InvalidTokenException('The access token is not a signed JWT.');
    }
    try {
      $header = Jwt::decodeHeader($token);
      $claims = Jwt::decodeClaims($token);
    }
    catch (\InvalidArgumentException) {
      throw new InvalidTokenException('The access token is not a signed JWT.');
    }

    $type = $header['typ'] ?? NULL;
    if (!is_string($type) || !in_array(strtolower($type), self::TYPES, TRUE)) {
      throw new InvalidTokenException('The token is not an access token: its "typ" is not "at+jwt".');
    }
    $algorithm = $header['alg'] ?? NULL;
    if (!is_string($algorithm) || !in_array($algorithm, self::ALGORITHMS, TRUE)) {
      throw new InvalidTokenException('The token\'s signature algorithm is not accepted.');
    }
    if (array_key_exists('crit', $header)) {
      throw new InvalidTokenException('The token has critical header parameters this server does not understand.');
    }
    $kid = $header['kid'] ?? NULL;
    if ($kid !== NULL && !is_string($kid)) {
      throw new InvalidTokenException('The token\'s "kid" is not a string.');
    }

    $this->verifySignature($token, $algorithm, $kid, $server);

    if (($claims['iss'] ?? NULL) !== $server->getIssuer()) {
      throw new InvalidTokenException('The token is not from this storage\'s authorization server.');
    }

    $aud = $claims['aud'] ?? NULL;
    $audiences = is_array($aud) ? $aud : [$aud];
    if (count($audiences) !== 1 || !in_array($audiences[0] ?? NULL, [$audience, rtrim($audience, '/')], TRUE)) {
      throw new InvalidTokenException('The token is not for this storage: its "aud" must be the storage URI alone.');
    }

    $now = $this->time->getCurrentTime();
    $skew = (int) ($this->configFactory->get('lws_authz.settings')->get('clock_skew') ?? 60);
    $exp = self::numericDate($claims, 'exp', TRUE);
    if ($now >= $exp + $skew) {
      throw new InvalidTokenException('The token has expired.');
    }
    $nbf = self::numericDate($claims, 'nbf', FALSE);
    if ($nbf !== NULL && $now < $nbf - $skew) {
      throw new InvalidTokenException('The token is not valid yet.');
    }
    if (self::numericDate($claims, 'iat', TRUE) > $now + $skew) {
      throw new InvalidTokenException('The token was issued in the future.');
    }

    $subject = $claims['sub'] ?? NULL;
    $client = $claims['client_id'] ?? NULL;
    $tokenId = $claims['jti'] ?? NULL;
    if (!self::isUri($subject) || !self::isUri($client) || !is_string($tokenId) || $tokenId === '') {
      throw new InvalidTokenException('The token lacks "sub" or "client_id" as a URI, or "jti".');
    }
    return new RequestingAgent($subject, $client, $server->getIssuer(), $tokenId);
  }

  /**
   * Verifies the signature with a key of the server.
   *
   * @throws \Drupal\lws_authz\Token\InvalidTokenException
   */
  private function verifySignature(string $token, string $algorithm, ?string $kid, AuthorizationServerInterface $server): void {
    try {
      $keys = $this->keys->keySet($server);
      // A key ID the server's cached keys lack may be a rotated-in key.
      if ($kid !== NULL && !$keys->hasKeyId($kid)) {
        $keys = $this->keys->keySet($server, TRUE);
      }
    }
    catch (KeysUnavailableException $e) {
      $this->logger->warning('An access token could not be checked: @message', ['@message' => $e->getMessage()]);
      throw new InvalidTokenException('The authorization server\'s keys are unavailable.', 0, $e);
    }
    foreach ($keys->candidates($kid, $algorithm) as $key) {
      if (Jwt::verify($token, $key)) {
        return;
      }
    }
    throw new InvalidTokenException('The token\'s signature does not verify with a key of this storage\'s authorization server.');
  }

  /**
   * A NumericDate claim (RFC 7519 §2).
   *
   * @param array<array-key, mixed> $claims
   *   The claims.
   * @param string $name
   *   The claim name.
   * @param bool $required
   *   Whether the claim must be present.
   *
   * @throws \Drupal\lws_authz\Token\InvalidTokenException
   */
  private static function numericDate(array $claims, string $name, bool $required): ?float {
    $value = $claims[$name] ?? NULL;
    if ($value === NULL && !$required) {
      return NULL;
    }
    if (!is_int($value) && !is_float($value)) {
      throw new InvalidTokenException(sprintf('The token\'s "%s" is missing or not a number.', $name));
    }
    return (float) $value;
  }

  /**
   * Whether a claim value is an absolute URI.
   */
  private static function isUri(mixed $value): bool {
    return is_string($value) && preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:[^\s]+$/', $value) === 1;
  }

}
