<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Controller;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\lws_authz\Server\LocalAuthorizationServer;
use Drupal\lws_authz\Server\OAuthError;
use Drupal\lws_authz\Server\SigningKeys;
use Drupal\lws_authz\Server\TokenExchange;
use Ebremer\Lws\TokenType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The endpoints of this site's authorization server.
 *
 * Its metadata (RFC 8414, LWS Core §5.2.2), its key set, and its token
 * endpoint (RFC 8693, LWS Core §5.2.3). Clients are public: none
 * authenticates, the credential identifies it.
 */
final class AuthorizationServerController implements ContainerInjectionInterface {

  use AutowireTrait;

  /**
   * The largest token request body accepted, in bytes.
   */
  public const MAX_BYTES = 65536;

  /**
   * How long clients may cache the metadata and key set, in seconds.
   */
  private const MAX_AGE = 300;

  /**
   * The headers that keep a token response out of caches.
   */
  private const NO_STORE = ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'];

  public function __construct(
    private readonly LocalAuthorizationServer $server,
    private readonly SigningKeys $keys,
    private readonly TokenExchange $exchange,
  ) {}

  /**
   * Serves the metadata.
   */
  public function metadata(): JsonResponse {
    return self::json($this->server->metadata(), 200, ['Cache-Control' => 'public, max-age=' . self::MAX_AGE]);
  }

  /**
   * Serves the public signing keys (RFC 7517 §5).
   */
  public function jwks(): JsonResponse {
    return self::json($this->keys->jwks(), 200, [
      'Content-Type' => 'application/jwk-set+json',
      'Cache-Control' => 'public, max-age=' . self::MAX_AGE,
    ]);
  }

  /**
   * Exchanges a credential for an access token.
   *
   * The response, an error too, is never cached (RFC 6749 §5.1).
   */
  public function token(Request $request): JsonResponse {
    try {
      $issued = $this->exchange->exchange(self::parameters($request), $request->getClientIp() ?? '');
    }
    catch (OAuthError $e) {
      return self::json(['error' => $e->error, 'error_description' => $e->getMessage()], $e->status, $e->headers + self::NO_STORE);
    }
    return self::json([
      'access_token' => $issued->accessToken,
      'issued_token_type' => TokenType::ACCESS_TOKEN,
      'token_type' => 'Bearer',
      'expires_in' => $issued->expiresIn,
    ], 200, self::NO_STORE);
  }

  /**
   * The parameters of a token request.
   *
   * The body is parsed here rather than by PHP, which would keep only the
   * last of repeated parameters (RFC 6749 §3.2 forbids repeating one) and
   * rewrite names.
   *
   * @return array<string, string>
   *   The parameters.
   *
   * @throws \Drupal\lws_authz\Server\OAuthError
   *   When the body is not a form, too large, or repeats a parameter.
   */
  private static function parameters(Request $request): array {
    $type = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));
    if ($type !== 'application/x-www-form-urlencoded') {
      throw OAuthError::invalidRequest('The request body must be application/x-www-form-urlencoded.');
    }
    if ((int) $request->headers->get('Content-Length') > self::MAX_BYTES) {
      throw new OAuthError(413, 'invalid_request', 'The request body is too large.');
    }
    $body = (string) $request->getContent();
    if (strlen($body) > self::MAX_BYTES) {
      throw new OAuthError(413, 'invalid_request', 'The request body is too large.');
    }
    $parameters = [];
    foreach (explode('&', $body) as $pair) {
      if ($pair === '') {
        continue;
      }
      [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
      $name = urldecode($name);
      if (array_key_exists($name, $parameters)) {
        throw OAuthError::invalidRequest(sprintf('The parameter "%s" appears more than once.', $name));
      }
      $parameters[$name] = urldecode($value);
    }
    return $parameters;
  }

  /**
   * A JSON response.
   *
   * @param array<string, mixed> $data
   *   The document.
   * @param int $status
   *   The status code.
   * @param array<string, string> $headers
   *   The headers.
   */
  private static function json(array $data, int $status, array $headers): JsonResponse {
    $response = new JsonResponse(NULL, $status, $headers);
    $response->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    // After the options, so that the body is encoded with them; setData()
    // keeps a Content-Type that is already set.
    return $response->setData($data);
  }

}
