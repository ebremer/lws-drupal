<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Authentication;

use Drupal\Core\Authentication\AuthenticationProviderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\lws\Agent\AgentUsersInterface;
use Drupal\lws\Agent\Authentication;
use Drupal\lws\Routing\LwsUrlParser;
use Drupal\lws\Storage\StorageRegistryInterface;
use Drupal\lws_authz\AuthorizationServers;
use Drupal\lws_authz\Token\AccessTokenValidator;
use Drupal\lws_authz\Token\InvalidTokenException;
use Ebremer\Lws\Vocabulary;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Authenticates requests in the LWS URL space with bearer access tokens.
 *
 * It claims every request in the URL space, with or without a token, so that
 * no other provider applies there: in particular, a session cookie never
 * authenticates an LWS request. It also claims requests for protected
 * resource metadata, which it leaves anonymous whatever they carry: clients
 * ask for it because their token was refused, and no other provider may then
 * refuse them. Elsewhere it never applies, which leaves "Bearer" to other
 * modules, such as simple_oauth.
 *
 * It never rejects a request. It records the outcome on the request for the
 * access check, together with what a 401 needs: the storage, its
 * authorization server, and whether the challenge keeps the parameters of the
 * drafts before 19 October 2026 (lws_authz.settings:legacy_challenge;
 * DESIGN.md §5.4).
 *
 * With lws_agent_users, an agent that acts as a Drupal user is that user for
 * the request, and carries what the user makes of it (AgentUsersInterface).
 * Only here: the token reaches no other route, and yields no session.
 */
final class LwsBearerProvider implements AuthenticationProviderInterface {

  /**
   * An RFC 6750 b64token.
   */
  private const TOKEN = '/^Bearer +([A-Za-z0-9\-._~+\/]+=*) *$/i';

  public function __construct(
    private readonly LwsUrlParser $parser,
    private readonly ?StorageRegistryInterface $storages,
    private readonly AuthorizationServers $servers,
    private readonly AccessTokenValidator $validator,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly ?AgentUsersInterface $users = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(Request $request) {
    $path = $request->getPathInfo();
    return $this->parser->parse($path) !== NULL || str_starts_with($path . '/', Vocabulary::WELL_KNOWN_OAUTH_PROTECTED_RESOURCE . '/');
  }

  /**
   * {@inheritdoc}
   */
  public function authenticate(Request $request) {
    $authentication = $this->outcome($request);
    $user = $authentication->isAuthenticated() ? $this->users?->find($authentication->agent, TRUE) : NULL;
    if ($user !== NULL) {
      $authentication = Authentication::authenticated($user->agent, (string) $authentication->storageUri, (string) $authentication->issuer);
    }
    $request->attributes->set(Authentication::ATTRIBUTE, $authentication);
    if (!$authentication->isAuthenticated()) {
      return NULL;
    }
    return $user->account ?? new LwsAccount($authentication->agent);
  }

  /**
   * Authenticates the request.
   */
  private function outcome(Request $request): Authentication {
    $slug = $this->parser->parse($request->getPathInfo())?->storage;
    $storage = $slug === NULL ? NULL : $this->storages?->get($slug);
    if ($storage === NULL) {
      // Nothing to protect: the request will be answered with a 404, or is
      // for protected resource metadata.
      return Authentication::anonymous();
    }
    $server = $this->servers->forStorage($storage);
    $issuer = $server?->getIssuer();
    $legacy = (bool) $this->configFactory->get('lws_authz.settings')->get('legacy_challenge');

    $credentials = $request->headers->all('authorization');
    $bearer = array_values(array_filter($credentials, static fn (?string $value): bool => preg_match('/^Bearer(?: |$)/i', (string) $value) === 1));
    if ($bearer === []) {
      return Authentication::anonymous($storage->uri, $issuer, $legacy);
    }
    if (count($credentials) > 1) {
      return Authentication::failed('invalid_request', 'The request has more than one Authorization header.', $storage->uri, $issuer, $legacy);
    }
    if (preg_match(self::TOKEN, (string) $bearer[0], $matches) !== 1) {
      return Authentication::failed('invalid_request', 'The Authorization header is not "Bearer" followed by a token.', $storage->uri, $issuer, $legacy);
    }
    if ($server === NULL) {
      return Authentication::failed('invalid_token', 'This storage trusts no authorization server.', $storage->uri);
    }

    try {
      $agent = $this->validator->validate($matches[1], $server, $storage->uri);
    }
    catch (InvalidTokenException $e) {
      // A hash prefix identifies the token in logs without disclosing it.
      $this->logger->info('Rejected access token @hash for @storage: @reason', [
        '@hash' => substr(hash('sha256', $matches[1]), 0, 12),
        '@storage' => $storage->uri,
        '@reason' => $e->getMessage(),
      ]);
      return Authentication::failed('invalid_token', $e->getMessage(), $storage->uri, $issuer, $legacy);
    }
    return Authentication::authenticated($agent, $storage->uri, (string) $issuer);
  }

}
