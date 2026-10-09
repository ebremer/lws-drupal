<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Authentication;

use Drupal\Core\Authentication\AuthenticationProviderInterface;
use Drupal\lws\Agent\AgentUsersInterface;
use Drupal\lws\Agent\Authentication;
use Drupal\lws\Routing\LwsUrlParser;
use Drupal\lws\Storage\StorageRegistryInterface;
use Drupal\lws_authz\AuthorizationServers;
use Drupal\lws_authz\Token\AccessTokenValidator;
use Drupal\lws_authz\Token\InvalidTokenException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Authenticates requests in the LWS URL space with bearer access tokens.
 *
 * It claims every request in the URL space, with or without a token, so that
 * no other provider applies there: in particular, a session cookie never
 * authenticates an LWS request. Elsewhere it never applies, which leaves
 * "Bearer" to other modules, such as simple_oauth.
 *
 * It never rejects a request. It records the outcome on the request for the
 * access check, together with the realm and authorization server that a 401
 * challenge needs (DESIGN.md §5.4).
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
    private readonly LoggerInterface $logger,
    private readonly ?AgentUsersInterface $users = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(Request $request) {
    return $this->parser->parse($request->getPathInfo()) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function authenticate(Request $request) {
    $authentication = $this->outcome($request);
    $user = $authentication->isAuthenticated() ? $this->users?->find($authentication->agent, TRUE) : NULL;
    if ($user !== NULL) {
      $authentication = Authentication::authenticated($user->agent, (string) $authentication->realm, (string) $authentication->asUri);
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
      // Nothing to protect: the request will be answered with a 404.
      return Authentication::anonymous();
    }
    $server = $this->servers->forStorage($storage);
    $realm = $storage->uri;
    $asUri = $server?->getIssuer();

    $credentials = $request->headers->all('authorization');
    $bearer = array_values(array_filter($credentials, static fn (?string $value): bool => preg_match('/^Bearer(?: |$)/i', (string) $value) === 1));
    if ($bearer === []) {
      return Authentication::anonymous($realm, $asUri);
    }
    if (count($credentials) > 1) {
      return Authentication::failed('invalid_request', 'The request has more than one Authorization header.', $realm, $asUri);
    }
    if (preg_match(self::TOKEN, (string) $bearer[0], $matches) !== 1) {
      return Authentication::failed('invalid_request', 'The Authorization header is not "Bearer" followed by a token.', $realm, $asUri);
    }
    if ($server === NULL) {
      return Authentication::failed('invalid_token', 'This storage trusts no authorization server.', $realm);
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
      return Authentication::failed('invalid_token', $e->getMessage(), $realm, $asUri);
    }
    return Authentication::authenticated($agent, $realm, (string) $asUri);
  }

}
