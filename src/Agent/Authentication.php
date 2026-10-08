<?php

declare(strict_types=1);

namespace Drupal\lws\Agent;

use Symfony\Component\HttpFoundation\Request;

/**
 * The outcome of authenticating a request in the LWS URL space.
 *
 * The authentication provider of lws_authz keeps it on the request. It never
 * rejects a request itself: access checks read the outcome after routing, and
 * LwsExceptionSubscriber turns a refusal into a 401 challenge from the realm
 * and authorization server recorded here (LWS Core §5.2.1, §5.2.4.2).
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
   * @param string|null $realm
   *   The protection space: the URI of the storage the request addresses.
   * @param string|null $asUri
   *   The authorization server that issues tokens for the realm.
   */
  private function __construct(
    public readonly RequestingAgent $agent,
    public readonly ?string $error = NULL,
    public readonly ?string $errorDescription = NULL,
    public readonly ?string $realm = NULL,
    public readonly ?string $asUri = NULL,
  ) {}

  /**
   * A request that presented no bearer token.
   */
  public static function anonymous(?string $realm = NULL, ?string $asUri = NULL): self {
    return new self(RequestingAgent::anonymous(), realm: $realm, asUri: $asUri);
  }

  /**
   * A request whose token was valid.
   */
  public static function authenticated(RequestingAgent $agent, string $realm, string $asUri): self {
    return new self($agent, realm: $realm, asUri: $asUri);
  }

  /**
   * A request whose token, or the way it was presented, was rejected.
   *
   * @param string $error
   *   The RFC 6750 error code: "invalid_request" or "invalid_token".
   * @param string $description
   *   Why, in words safe to show the client.
   * @param string|null $realm
   *   The protection space.
   * @param string|null $asUri
   *   The authorization server for the realm.
   */
  public static function failed(string $error, string $description, ?string $realm = NULL, ?string $asUri = NULL): self {
    return new self(RequestingAgent::anonymous(), $error, $description, $realm, $asUri);
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
