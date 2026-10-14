<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Server;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws\Routing\LwsUrlParser;
use Drupal\lws_authz\AuthenticationSuite\AuthenticationSuiteManager;
use Drupal\lws_authz\AuthorizationServerInterface;
use Ebremer\Lws\Auth\AuthorizationServerMetadata;
use Ebremer\Lws\Vocabulary;

/**
 * This site's own authorization server (LWS Core §5.2.2, §5.2.3).
 *
 * Its issuer is the LWS base URL without a trailing slash (DESIGN.md D13):
 * the origin for a site at the root of its host, whose metadata then sits
 * exactly at /.well-known/lws-configuration, and the origin and path for a
 * site below it, whose metadata is at /.well-known/lws-configuration followed
 * by that path (RFC 8414 §3.1). Drupal serves it at its own
 * /.well-known/lws-configuration either way; under a path, the web server or
 * proxy maps the RFC 8414 URL there. Its endpoints live under the LWS prefix:
 * {prefix}/oauth/token and {prefix}/oauth/jwks.
 */
final class LocalAuthorizationServer implements AuthorizationServerInterface {

  /**
   * The ID storages and settings name it by.
   */
  public const ID = 'local';

  /**
   * The path of its metadata (LWS Core §5.2.2).
   */
  public const METADATA_PATH = '/.well-known/lws-configuration';

  public function __construct(
    private readonly LwsUrlGenerator $urls,
    private readonly LwsUrlParser $parser,
    private readonly SigningKeys $keys,
    private readonly AuthenticationSuiteManager $suites,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return self::ID;
  }

  /**
   * {@inheritdoc}
   */
  public function label(): TranslatableMarkup {
    return new TranslatableMarkup('This site');
  }

  /**
   * {@inheritdoc}
   */
  public function getIssuer(): string {
    $parts = parse_url($this->urls->baseUrl());
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
      return '';
    }
    // An IPv6 host keeps its brackets. The path keeps its case, and loses a
    // trailing slash, as RFC 8414 §3.1 drops one before inserting the
    // well-known segment.
    return strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '') . rtrim($parts['path'] ?? '', '/');
  }

  /**
   * The URL of its metadata: the well-known segment before the issuer's path.
   *
   * Empty when it has no issuer.
   */
  public function metadataUrl(): string {
    $issuer = $this->getIssuer();
    return $issuer === '' ? '' : AuthorizationServerMetadata::metadataUrl($issuer);
  }

  /**
   * {@inheritdoc}
   *
   * The published signing keys, read from the key directory.
   */
  public function getJwks(): string {
    return json_encode($this->keys->jwks(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  }

  /**
   * Whether it can issue tokens: whether it has a key directory.
   */
  public function isAvailable(): bool {
    return $this->keys->directory() !== NULL && $this->getIssuer() !== '';
  }

  /**
   * The URL of its token endpoint.
   */
  public function tokenEndpoint(): string {
    return $this->urls->baseUrl() . $this->parser->prefix() . '/oauth/token';
  }

  /**
   * The URL of its key set.
   */
  public function jwksUri(): string {
    return $this->urls->baseUrl() . $this->parser->prefix() . '/oauth/jwks';
  }

  /**
   * Its metadata document (RFC 8414 §2, LWS Core §5.2.2).
   *
   * @return array<string, mixed>
   *   The document.
   */
  public function metadata(): array {
    $tokenTypes = [];
    $identifierTypes = [];
    foreach ($this->suites->enabled() as $tokenType => $suite) {
      $tokenTypes[] = $tokenType;
      $identifierTypes = [...$identifierTypes, ...$suite->subjectIdentifierTypes()];
    }
    return [
      'issuer' => $this->getIssuer(),
      'token_endpoint' => $this->tokenEndpoint(),
      'jwks_uri' => $this->jwksUri(),
      'grant_types_supported' => [Vocabulary::GRANT_TYPE_TOKEN_EXCHANGE],
      'response_types_supported' => ['token'],
      'token_endpoint_auth_methods_supported' => ['none'],
      'claims_supported' => ['sub', 'iss', 'client_id', 'aud', 'exp', 'iat', 'jti'],
      'subject_token_types_supported' => $tokenTypes,
      'subject_identifier_types_supported' => array_values(array_unique($identifierTypes)),
    ];
  }

}
