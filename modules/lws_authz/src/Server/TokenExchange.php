<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Server;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws\Routing\LwsUrlParser;
use Drupal\lws\Storage\StorageRef;
use Drupal\lws\Storage\StorageRegistryInterface;
use Drupal\lws_authz\AuthenticationSuite\AuthenticationSuiteManager;
use Drupal\lws_authz\AuthenticationSuite\InvalidCredentialException;
use Drupal\lws_authz\AuthenticationSuite\TokenExchangeContext;
use Drupal\lws_authz\AuthorizationServers;
use Drupal\lws_authz\Token\KeysUnavailableException;
use Ebremer\Lws\Auth\Jwt;
use Ebremer\Lws\TokenType;
use Ebremer\Lws\Vocabulary;
use Psr\Log\LoggerInterface;

/**
 * OAuth 2.0 Token Exchange at this site's authorization server.
 *
 * LWS Core §5.2.3, RFC 8693. A client presents an authentication credential
 * as the subject token, and a storage as the resource, and receives an RFC
 * 9068 access token for that storage. The steps, stopping at the first error:
 *
 * 1. the grant type is token exchange (else unsupported_grant_type);
 * 2. resource, subject_token and subject_token_type are present;
 * 3. the resource is the URI of a storage of this site that trusts this
 *    authorization server (else invalid_target);
 * 4. an enabled authentication suite takes the subject token type, and
 *    validates the credential;
 * 5. an access token is minted: "aud" the resource as given, "sub" and
 *    "client_id" from the credential, and a lifetime of the configured one at
 *    most, never outliving the credential.
 *
 * Requests are rate-limited per client address, and issued tokens per
 * subject, through flood control. Subject tokens are never logged; a hash
 * prefix identifies one.
 */
final class TokenExchange {

  /**
   * The flood control event of a token request, per client address.
   */
  public const FLOOD_REQUEST = 'lws_authz.token_request';

  /**
   * The flood control event of an issued token, per subject.
   */
  public const FLOOD_ISSUED = 'lws_authz.token_issued';

  /**
   * The period the rate limits count over, in seconds.
   */
  public const WINDOW = 60;

  public function __construct(
    private readonly LocalAuthorizationServer $server,
    private readonly AuthorizationServers $servers,
    private readonly AuthenticationSuiteManager $suites,
    private readonly SigningKeys $keys,
    private readonly LwsUrlGenerator $urls,
    private readonly LwsUrlParser $parser,
    private readonly FloodInterface $flood,
    private readonly UuidInterface $uuid,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    // Provided by lws_storage; without it there are no storages to issue for.
    private readonly ?StorageRegistryInterface $storages = NULL,
  ) {}

  /**
   * Exchanges a credential for an access token.
   *
   * @param array<string, string> $parameters
   *   The token request's parameters, each present once.
   * @param string $client
   *   The client's address, for rate limiting.
   *
   * @throws \Drupal\lws_authz\Server\OAuthError
   *   When the request cannot be honoured.
   */
  public function exchange(array $parameters, string $client): IssuedToken {
    $settings = $this->configFactory->get('lws_authz.settings');
    if (!$this->flood->isAllowed(self::FLOOD_REQUEST, (int) $settings->get('rate_limits.client'), self::WINDOW, $client)) {
      throw new OAuthError(429, 'invalid_request', 'Too many token requests; try again in a minute.', ['Retry-After' => (string) self::WINDOW]);
    }
    $this->flood->register(self::FLOOD_REQUEST, self::WINDOW, $client);
    try {
      return $this->issue($parameters, (int) $settings->get('rate_limits.subject'));
    }
    catch (OAuthError $e) {
      $this->logger->info('Refused a token request from @client: @error, @description (subject token @hash)', [
        '@client' => $client,
        '@error' => $e->error,
        '@description' => $e->getMessage(),
        '@hash' => isset($parameters['subject_token']) ? substr(hash('sha256', $parameters['subject_token']), 0, 12) : 'none',
      ]);
      throw $e;
    }
  }

  /**
   * Validates a request and issues the token.
   *
   * @param array<string, string> $parameters
   *   The parameters.
   * @param int $subjectLimit
   *   The tokens a subject may be issued per window.
   *
   * @throws \Drupal\lws_authz\Server\OAuthError
   */
  private function issue(array $parameters, int $subjectLimit): IssuedToken {
    $grantType = $parameters['grant_type'] ?? NULL;
    if ($grantType === NULL || $grantType === '') {
      throw OAuthError::invalidRequest('grant_type is required.');
    }
    if ($grantType !== Vocabulary::GRANT_TYPE_TOKEN_EXCHANGE) {
      throw new OAuthError(400, 'unsupported_grant_type', 'This authorization server supports only ' . Vocabulary::GRANT_TYPE_TOKEN_EXCHANGE . '.');
    }
    $resource = $parameters['resource'] ?? '';
    if ($resource === '') {
      throw OAuthError::invalidRequest('resource is required: the URI of the storage, the realm of its 401 challenge.');
    }
    $subjectToken = $parameters['subject_token'] ?? '';
    $subjectTokenType = $parameters['subject_token_type'] ?? '';
    if ($subjectToken === '' || $subjectTokenType === '') {
      throw OAuthError::invalidRequest('subject_token and subject_token_type are required.');
    }
    if (isset($parameters['requested_token_type']) && $parameters['requested_token_type'] !== TokenType::ACCESS_TOKEN) {
      throw OAuthError::invalidRequest('Only ' . TokenType::ACCESS_TOKEN . ' tokens are issued.');
    }
    if (isset($parameters['actor_token'])) {
      throw OAuthError::invalidRequest('Delegation (actor_token) is not supported.');
    }
    if (!$this->server->isAvailable()) {
      throw new OAuthError(503, 'temporarily_unavailable', 'This authorization server has no signing key directory.');
    }

    $storage = $this->storage($resource);
    if ($storage === NULL || $this->servers->forStorage($storage)?->id() !== LocalAuthorizationServer::ID) {
      throw new OAuthError(400, 'invalid_target', 'The resource is not a storage this authorization server issues tokens for.');
    }
    if (isset($parameters['audience']) && !in_array($parameters['audience'], [$storage->uri, rtrim($storage->uri, '/')], TRUE)) {
      throw new OAuthError(400, 'invalid_target', 'The audience must be the storage the resource names.');
    }

    $suite = $this->suites->forTokenType($subjectTokenType)
      ?? throw OAuthError::invalidRequest('This authorization server does not accept subject tokens of type ' . $subjectTokenType . '.');
    $now = (int) $this->time->getCurrentTime();
    $settings = $this->configFactory->get('lws_authz.settings');
    $context = new TokenExchangeContext($this->server->getIssuer(), $now, (int) $settings->get('clock_skew'));
    try {
      $credential = $suite->validate($subjectToken, $context);
    }
    catch (InvalidCredentialException $e) {
      throw OAuthError::invalidRequest($e->getMessage());
    }

    $identifier = hash('sha256', $credential->subject);
    if (!$this->flood->isAllowed(self::FLOOD_ISSUED, $subjectLimit, self::WINDOW, $identifier)) {
      throw new OAuthError(429, 'invalid_request', 'Too many tokens issued to this subject; try again in a minute.', ['Retry-After' => (string) self::WINDOW]);
    }
    $expires = min($now + max(1, (int) $settings->get('token_lifetime')), $credential->expiresAt);
    if ($expires <= $now) {
      throw OAuthError::invalidRequest('The credential expires too soon for a token to be issued for it.');
    }
    try {
      $key = $this->keys->active();
    }
    catch (KeysUnavailableException $e) {
      $this->logger->error('Cannot issue access tokens: @message', ['@message' => $e->getMessage()]);
      throw new OAuthError(503, 'temporarily_unavailable', 'This authorization server cannot sign tokens at the moment.');
    }
    $token = Jwt::sign(['typ' => 'at+jwt', 'kid' => $key['kid']], [
      'iss' => $this->server->getIssuer(),
      'sub' => $credential->subject,
      'client_id' => $credential->client,
      'aud' => $resource,
      'iat' => $now,
      'exp' => $expires,
      'jti' => $this->uuid->generate(),
    ], $key['key']);
    $this->flood->register(self::FLOOD_ISSUED, self::WINDOW, $identifier);
    return new IssuedToken($token, $expires - $now);
  }

  /**
   * The storage of this site a resource URI names, with or without its slash.
   */
  private function storage(string $resource): ?StorageRef {
    $prefix = $this->urls->baseUrl() . $this->parser->prefix() . '/';
    if ($this->storages === NULL || !str_starts_with($resource, $prefix)) {
      return NULL;
    }
    $slug = rtrim(substr($resource, strlen($prefix)), '/');
    if (preg_match(LwsUrlParser::SLUG, $slug) !== 1 || in_array($slug, LwsUrlParser::RESERVED, TRUE)) {
      return NULL;
    }
    $storage = $this->storages->get($slug);
    return $storage !== NULL && in_array($resource, [$storage->uri, rtrim($storage->uri, '/')], TRUE) ? $storage : NULL;
  }

}
