<?php

declare(strict_types=1);

namespace Drupal\lws\Agent;

use Symfony\Component\HttpFoundation\Request;

/**
 * The outcome of authenticating a request in the LWS URL space.
 *
 * The authentication provider of lws_authz keeps it on the request. It never
 * rejects a request itself: access checks read the outcome after routing, and
 * LwsExceptionSubscriber turns a refusal into a 401 from what is recorded
 * here: the storage the request addresses, which the 401 links to and which
 * a client asks a token for, and whether the storage has an authorization
 * server whose protected resource metadata the challenge can name (LWS Core,
 * Authorization Server Discovery).
 */
final class Authentication {

  /**
   * The request attribute that holds the outcome.
   */
  public const ATTRIBUTE = '_lws_auth';

  /**
   * Constructs an outcome; use the named constructors.
   *
   * @param \Drupal\lws\Agent\RequestingAgent $agent
   *   The requesting agent; anonymous unless a token was valid.
   * @param string|null $error
   *   For a rejected token, the RFC 6750 error code.
   * @param string|null $errorDescription
   *   For a rejected token, why, in words safe to show the client.
   * @param string|null $storageUri
   *   The URI of the storage the request addresses, which the audience of
   *   its tokens must include.
   * @param string|null $issuer
   *   The issuer of the authorization server that issues tokens for the
   *   storage.
   * @param bool $legacyChallenge
   *   Whether a 401 challenge also carries "as_uri" and "realm", for clients
   *   of the drafts before 19 October 2026.
   */
  private function __construct(
    public readonly RequestingAgent $agent,
    public readonly ?string $error = NULL,
    public readonly ?string $errorDescription = NULL,
    public readonly ?string $storageUri = NULL,
    public readonly ?string $issuer = NULL,
    public readonly bool $legacyChallenge = FALSE,
  ) {}

  /**
   * A request that presented no bearer token.
   *
   * @param string|null $storageUri
   *   The storage the request addresses.
   * @param string|null $issuer
   *   The authorization server for the storage.
   * @param bool $legacyChallenge
   *   Whether a challenge also carries "as_uri" and "realm".
   */
  public static function anonymous(?string $storageUri = NULL, ?string $issuer = NULL, bool $legacyChallenge = FALSE): self {
    return new self(RequestingAgent::anonymous(), storageUri: $storageUri, issuer: $issuer, legacyChallenge: $legacyChallenge);
  }

  /**
   * A request whose token was valid.
   */
  public static function authenticated(RequestingAgent $agent, string $storageUri, string $issuer): self {
    return new self($agent, storageUri: $storageUri, issuer: $issuer);
  }

  /**
   * A request whose token, or the way it was presented, was rejected.
   *
   * @param string $error
   *   The RFC 6750 error code: "invalid_request" or "invalid_token".
   * @param string $description
   *   Why, in words safe to show the client.
   * @param string|null $storageUri
   *   The storage the request addresses.
   * @param string|null $issuer
   *   The authorization server for the storage.
   * @param bool $legacyChallenge
   *   Whether a challenge also carries "as_uri" and "realm".
   */
  public static function failed(string $error, string $description, ?string $storageUri = NULL, ?string $issuer = NULL, bool $legacyChallenge = FALSE): self {
    return new self(RequestingAgent::anonymous(), $error, $description, $storageUri, $issuer, $legacyChallenge);
  }

  /**
   * The outcome recorded on a request; anonymous if there is none.
   */
  public static function fromRequest(Request $request): self {
    $authentication = $request->attributes->get(self::ATTRIBUTE);
    return $authentication instanceof self ? $authentication : self::anonymous();
  }

  /**
   * Whether a valid token identified the agent.
   */
  public function isAuthenticated(): bool {
    return $this->agent->isAuthenticated();
  }

  /**
   * Whether a token was presented and rejected.
   */
  public function isFailed(): bool {
    return $this->error !== NULL;
  }

}
