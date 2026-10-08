<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Plugin\LwsAuthenticationSuite;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lws_authz\Attribute\LwsAuthenticationSuite;
use Drupal\lws_authz\AuthenticationSuite\AuthenticationSuiteBase;
use Drupal\lws_authz\AuthenticationSuite\InvalidCredentialException;
use Drupal\lws_authz\AuthenticationSuite\TokenExchangeContext;
use Drupal\lws_authz\AuthenticationSuite\ValidatedCredential;
use Drupal\lws_authz\Cid\DocumentResolver;
use Drupal\lws_authz\Cid\UnresolvableSubjectException;
use Drupal\lws_authz\Entity\TrustedIssuerInterface;
use Drupal\lws_authz\Token\AccessTokenValidator;
use Drupal\lws_authz\Token\AuthorizationServerKeys;
use Drupal\lws_authz\Token\KeysUnavailableException;
use Ebremer\Lws\Auth\Jwt;
use Ebremer\Lws\TokenType;
use Ebremer\Lws\Vocabulary;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * ID Tokens of OpenID Providers (lws10-authn-openid).
 *
 * "sub" is the subject, "iss" the issuer and "azp" the client. An ID Token is
 * accepted only if:
 *
 * - its "alg" is one this site verifies (never "none", nor an HMAC), with no
 *   "crit", and its "typ", if any, is not that of an access token;
 * - it has "sub" and "azp" URIs, and an "iss" URL;
 * - its "aud" includes this authorization server; or, where a configured
 *   provider waives that, the client it was issued to, "azp" (OpenID Connect
 *   Core §3.1.3.7);
 * - it has "exp", in the future, and "iat", not in the future, within the
 *   clock skew, and is past any "nbf";
 * - its signature verifies with a key of the provider: pinned for a
 *   configured provider, or from its OpenID Connect Discovery document;
 * - the provider is trusted for the subject. A configured provider may be
 *   trusted for any subject. Otherwise the subject is dereferenced, and its
 *   controlled identifier document, whose "id" must be the subject, must have
 *   a service of type lws:OpenIdProvider whose endpoint is the issuer (§5).
 *   Providers that are not configured are trusted this way only if the
 *   authorization settings allow it.
 *
 * The signature is verified before the subject is dereferenced, so that only
 * an ID Token the issuer signed makes this site fetch a subject's document.
 */
#[LwsAuthenticationSuite(
  id: 'openid',
  label: new TranslatableMarkup('OpenID Connect'),
  token_type: TokenType::ID_TOKEN,
  subject_identifier_types: ['https'],
)]
final class OpenIdConnect extends AuthenticationSuiteBase implements ContainerFactoryPluginInterface {

  /**
   * The service type that names a subject's OpenID Provider.
   */
  public const OPENID_PROVIDER = Vocabulary::LWS_NS . 'OpenIdProvider';

  /**
   * An absolute URI.
   */
  private const URI = '/^[A-Za-z][A-Za-z0-9+.-]*:\S+$/';

  /**
   * Constructs the suite.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\lws_authz\Cid\DocumentResolver $documents
   *   Dereferences subjects.
   * @param \Drupal\lws_authz\Token\AuthorizationServerKeys $keys
   *   The keys of OpenID Providers.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, for configured providers.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected DocumentResolver $documents,
    protected AuthorizationServerKeys $keys,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('lws_authz.cid_resolver'),
      $container->get('lws_authz.keys'),
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(string $subjectToken, TokenExchangeContext $context): ValidatedCredential {
    if (substr_count($subjectToken, '.') !== 2) {
      throw new InvalidCredentialException('The ID Token is not a signed JWT.');
    }
    try {
      $header = Jwt::decodeHeader($subjectToken);
      $claims = Jwt::decodeClaims($subjectToken);
    }
    catch (\InvalidArgumentException) {
      throw new InvalidCredentialException('The ID Token is not a signed JWT.');
    }

    $algorithm = $header['alg'] ?? NULL;
    if (!is_string($algorithm) || !in_array($algorithm, AccessTokenValidator::ALGORITHMS, TRUE)) {
      throw new InvalidCredentialException(sprintf('The ID Token must be signed with %s.', implode(', ', AccessTokenValidator::ALGORITHMS)));
    }
    if (array_key_exists('crit', $header)) {
      throw new InvalidCredentialException('The ID Token has critical header parameters this server does not understand.');
    }
    $type = $header['typ'] ?? NULL;
    if (is_string($type) && in_array(strtolower($type), ['at+jwt', 'application/at+jwt'], TRUE)) {
      throw new InvalidCredentialException('The token is an access token, not an ID Token.');
    }
    $kid = $header['kid'] ?? NULL;
    if ($kid !== NULL && !is_string($kid)) {
      throw new InvalidCredentialException('The ID Token\'s "kid" is not a string.');
    }

    $subject = $claims['sub'] ?? NULL;
    if (!is_string($subject) || preg_match(self::URI, $subject) !== 1) {
      throw new InvalidCredentialException('The ID Token\'s "sub" is not a URI, so it is no LWS subject identifier.');
    }
    $issuer = $claims['iss'] ?? NULL;
    $scheme = is_string($issuer) ? strtolower((string) parse_url($issuer, PHP_URL_SCHEME)) : '';
    if (!is_string($issuer) || !in_array($scheme, ['https', 'http'], TRUE)) {
      throw new InvalidCredentialException('The ID Token\'s "iss" is not an issuer URL.');
    }
    $client = $claims['azp'] ?? NULL;
    if (!is_string($client) || preg_match(self::URI, $client) !== 1) {
      throw new InvalidCredentialException('The ID Token has no "azp" URI naming its client (lws10-authn-openid §3).');
    }

    $provider = $this->provider($issuer);
    $settings = $this->configFactory->get('lws_authz.settings');
    if ($provider === NULL && !$settings->get('openid.discovery')) {
      throw new InvalidCredentialException(sprintf('This site does not trust the OpenID Provider %s.', $issuer));
    }
    $this->checkAudience($claims, $client, $context, $provider?->requiresAsAudience() ?? (bool) $settings->get('openid.require_as_audience'));
    $expires = $this->checkTimes($claims, $context);
    $this->verifySignature($subjectToken, $algorithm, $kid, $issuer, $provider);
    if ($provider === NULL || $provider->verifiesSubject()) {
      $this->checkSubject($subject, $issuer);
    }
    return new ValidatedCredential($subject, $issuer, $client, (int) floor($expires));
  }

  /**
   * The enabled, configured provider of an issuer, if there is one.
   */
  private function provider(string $issuer): ?TrustedIssuerInterface {
    foreach ($this->entityTypeManager->getStorage('lws_trusted_issuer')->loadByProperties(['issuer' => $issuer]) as $provider) {
      if ($provider instanceof TrustedIssuerInterface && $provider->status() && $provider->getIssuer() === $issuer) {
        return $provider;
      }
    }
    return NULL;
  }

  /**
   * Checks the audience.
   *
   * @param array<array-key, mixed> $claims
   *   The claims.
   * @param string $client
   *   The client, "azp".
   * @param \Drupal\lws_authz\AuthenticationSuite\TokenExchangeContext $context
   *   The token exchange.
   * @param bool $requireServer
   *   Whether "aud" must include this authorization server, rather than the
   *   client.
   *
   * @throws \Drupal\lws_authz\AuthenticationSuite\InvalidCredentialException
   */
  private function checkAudience(array $claims, string $client, TokenExchangeContext $context, bool $requireServer): void {
    $audience = $claims['aud'] ?? NULL;
    $audiences = array_values(array_filter(is_array($audience) ? $audience : [$audience], 'is_string'));
    if ($audiences === []) {
      throw new InvalidCredentialException('The ID Token has no "aud".');
    }
    $server = $context->authorizationServer;
    if ($requireServer) {
      if (!in_array($server, $audiences, TRUE) && !in_array($server . '/', $audiences, TRUE)) {
        throw new InvalidCredentialException(sprintf('The ID Token is not for this authorization server: its "aud" must include %s.', $server));
      }
      return;
    }
    if (!in_array($client, $audiences, TRUE)) {
      throw new InvalidCredentialException('The ID Token was not issued to its "azp": its "aud" must include it (OpenID Connect Core §3.1.3.7).');
    }
  }

  /**
   * Checks "exp", "iat" and "nbf", and returns "exp".
   *
   * @param array<array-key, mixed> $claims
   *   The claims.
   * @param \Drupal\lws_authz\AuthenticationSuite\TokenExchangeContext $context
   *   The token exchange.
   *
   * @throws \Drupal\lws_authz\AuthenticationSuite\InvalidCredentialException
   */
  private function checkTimes(array $claims, TokenExchangeContext $context): float {
    $expires = self::numericDate($claims, 'exp');
    if ($expires === NULL || $context->now >= $expires + $context->clockSkew) {
      throw new InvalidCredentialException($expires === NULL ? 'The ID Token has no "exp".' : 'The ID Token has expired.');
    }
    $issued = self::numericDate($claims, 'iat');
    if ($issued === NULL || $issued > $context->now + $context->clockSkew) {
      throw new InvalidCredentialException($issued === NULL ? 'The ID Token has no "iat".' : 'The ID Token was issued in the future.');
    }
    $notBefore = self::numericDate($claims, 'nbf');
    if ($notBefore !== NULL && $context->now < $notBefore - $context->clockSkew) {
      throw new InvalidCredentialException('The ID Token is not valid yet.');
    }
    return $expires;
  }

  /**
   * Verifies the signature with the provider's keys.
   *
   * A key ID the cached keys lack makes them be fetched again, at most once a
   * minute per provider, as the provider may have rotated its keys.
   *
   * @throws \Drupal\lws_authz\AuthenticationSuite\InvalidCredentialException
   */
  private function verifySignature(string $token, string $algorithm, ?string $kid, string $issuer, ?TrustedIssuerInterface $provider): void {
    $pinned = $provider?->getJwks();
    try {
      $keys = $this->keys->openIdProviderKeys($issuer, $pinned);
      if ($kid !== NULL && $pinned === NULL && !$keys->hasKeyId($kid)) {
        $keys = $this->keys->openIdProviderKeys($issuer, NULL, TRUE);
      }
    }
    catch (KeysUnavailableException) {
      throw new InvalidCredentialException(sprintf('The keys of the OpenID Provider %s cannot be obtained.', $issuer));
    }
    foreach ($keys->candidates($kid, $algorithm) as $key) {
      if (Jwt::verify($token, $key)) {
        return;
      }
    }
    throw new InvalidCredentialException('The ID Token\'s signature does not verify with the keys of its issuer.');
  }

  /**
   * Checks that the subject's document names the issuer as its provider.
   *
   * @throws \Drupal\lws_authz\AuthenticationSuite\InvalidCredentialException
   */
  private function checkSubject(string $subject, string $issuer): void {
    try {
      $document = $this->documents->resolve($subject);
    }
    catch (UnresolvableSubjectException $e) {
      throw new InvalidCredentialException($e->getMessage(), 0, $e);
    }
    if (($document['id'] ?? NULL) !== $subject) {
      throw new InvalidCredentialException('The document the subject dereferences to is not the subject\'s: its "id" differs.');
    }
    if (!self::namesProvider($document, $issuer)) {
      throw new InvalidCredentialException(sprintf('The subject\'s document does not name %s as its OpenID Provider.', $issuer));
    }
  }

  /**
   * Whether a controlled identifier document names a provider (§5).
   *
   * Its "service" must have an entry of type lws:OpenIdProvider whose
   * "serviceEndpoint" is the issuer. The type is the full IRI, or a term or
   * compact IRI that the document's own context maps to it.
   *
   * @param array<array-key, mixed> $document
   *   The document.
   * @param string $issuer
   *   The provider's issuer identifier.
   */
  public static function namesProvider(array $document, string $issuer): bool {
    $services = $document['service'] ?? [];
    $services = is_array($services) && !array_is_list($services) ? [$services] : (array) $services;
    $types = self::openIdProviderTypes($document['@context'] ?? NULL);
    foreach ($services as $service) {
      if (!is_array($service)) {
        continue;
      }
      $type = $service['type'] ?? NULL;
      $named = array_intersect(is_array($type) ? $type : [$type], $types) !== [];
      $endpoint = $service['serviceEndpoint'] ?? NULL;
      if ($named && in_array($issuer, is_array($endpoint) ? $endpoint : [$endpoint], TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The spellings of lws:OpenIdProvider a document's context allows.
   *
   * @return list<string>
   *   The full IRI, and the term and compact IRIs the context defines.
   */
  private static function openIdProviderTypes(mixed $context): array {
    $types = [self::OPENID_PROVIDER];
    foreach (is_array($context) ? $context : [$context] as $entry) {
      if ($entry === Vocabulary::LWS_CONTEXT) {
        $types[] = 'OpenIdProvider';
      }
      elseif (is_array($entry)) {
        foreach ($entry as $term => $iri) {
          if ($iri === Vocabulary::LWS_NS) {
            $types[] = $term . ':OpenIdProvider';
          }
          elseif ($iri === self::OPENID_PROVIDER) {
            $types[] = (string) $term;
          }
        }
      }
    }
    return $types;
  }

  /**
   * A NumericDate claim, or NULL if it is absent.
   *
   * @param array<array-key, mixed> $claims
   *   The claims.
   * @param string $name
   *   The claim name.
   *
   * @throws \Drupal\lws_authz\AuthenticationSuite\InvalidCredentialException
   *   When it is present but not a number.
   */
  private static function numericDate(array $claims, string $name): ?float {
    $value = $claims[$name] ?? NULL;
    if ($value === NULL) {
      return NULL;
    }
    if (!is_int($value) && !is_float($value)) {
      throw new InvalidCredentialException(sprintf('The ID Token\'s "%s" is not a number.', $name));
    }
    return (float) $value;
  }

}
