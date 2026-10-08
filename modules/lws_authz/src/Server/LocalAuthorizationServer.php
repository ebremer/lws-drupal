<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Server;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws\Routing\LwsUrlParser;
use Drupal\lws_authz\AuthenticationSuite\AuthenticationSuiteManager;
use Drupal\lws_authz\AuthorizationServerInterface;
use Ebremer\Lws\Vocabulary;

/**
 * This site's own authorization server (LWS Core §5.2.2, §5.2.3).
 *
 * Its issuer is the origin of the LWS base URL, so that its metadata sits
 * exactly at /.well-known/lws-configuration (DESIGN.md D13). Its endpoints
 * live under the LWS prefix: {prefix}/oauth/token and {prefix}/oauth/jwks.
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
    // An IPv6 host keeps its brackets.
    return strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
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
