<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Plugin\LwsAuthenticationSuite;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lws_authz\Attribute\LwsAuthenticationSuite;
use Drupal\lws_authz\AuthenticationSuite\AuthenticationSuiteBase;
use Drupal\lws_authz\AuthenticationSuite\InvalidCredentialException;
use Drupal\lws_authz\AuthenticationSuite\TokenExchangeContext;
use Drupal\lws_authz\AuthenticationSuite\ValidatedCredential;
use Drupal\lws_authz\Cid\DocumentResolver;
use Drupal\lws_authz\Cid\UnresolvableSubjectException;
use Drupal\lws_authz\Cid\VerificationMethods;
use Drupal\lws_authz\Token\AccessTokenValidator;
use Ebremer\Lws\Auth\Jwt;
use Ebremer\Lws\TokenType;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Self-signed identity using controlled identifiers (lws10-authn-ssi-cid).
 *
 * The credential is a JWT the agent signs itself. It is accepted only if:
 *
 * - its "alg" is one this site verifies (never "none"), with no "crit";
 * - its "sub", "iss" and "client_id" are one URI, the subject;
 * - its "aud" includes this authorization server;
 * - it has "exp", in the future, and "iat", not in the future, within the
 *   clock skew, and is past any "nbf";
 * - its "kid" names a verification method of the subject's authentication
 *   relationship (CID 1.0 §3.3), not revoked or expired, whose key verifies
 *   the signature (RFC 7515 §5.2).
 *
 * The claims are checked before the subject is dereferenced, so that a
 * credential that could never be accepted makes this site fetch nothing.
 * Subjects are HTTPS URIs, whose document must have the subject as its "id",
 * did:key DIDs, resolved locally, and did:web DIDs, fetched over HTTPS.
 */
#[LwsAuthenticationSuite(
  id: 'ssi_cid',
  label: new TranslatableMarkup('Self-signed controlled identifier'),
  token_type: TokenType::JWT,
  subject_identifier_types: ['https', 'did:key', 'did:web'],
)]
final class SelfSignedCid extends AuthenticationSuiteBase implements ContainerFactoryPluginInterface {

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
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected DocumentResolver $documents,
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
    return new self($configuration, $plugin_id, $plugin_definition, $container->get('lws_authz.cid_resolver'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(string $subjectToken, TokenExchangeContext $context): ValidatedCredential {
    if (substr_count($subjectToken, '.') !== 2) {
      throw new InvalidCredentialException('The credential is not a signed JWT.');
    }
    try {
      $header = Jwt::decodeHeader($subjectToken);
      $claims = Jwt::decodeClaims($subjectToken);
    }
    catch (\InvalidArgumentException) {
      throw new InvalidCredentialException('The credential is not a signed JWT.');
    }

    $algorithm = $header['alg'] ?? NULL;
    if (!is_string($algorithm) || !in_array($algorithm, AccessTokenValidator::ALGORITHMS, TRUE)) {
      throw new InvalidCredentialException(sprintf('The credential must be signed with %s.', implode(', ', AccessTokenValidator::ALGORITHMS)));
    }
    if (array_key_exists('crit', $header)) {
      throw new InvalidCredentialException('The credential has critical header parameters this server does not understand.');
    }
    $kid = $header['kid'] ?? NULL;
    if (!is_string($kid) || $kid === '') {
      throw new InvalidCredentialException('The credential has no "kid" naming the verification method that signed it.');
    }

    $subject = $claims['sub'] ?? NULL;
    if (!is_string($subject) || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:\S+$/', $subject) !== 1) {
      throw new InvalidCredentialException('The credential has no "sub" URI.');
    }
    if (($claims['iss'] ?? NULL) !== $subject || ($claims['client_id'] ?? NULL) !== $subject) {
      throw new InvalidCredentialException('The credential\'s "sub", "iss" and "client_id" must be the same URI.');
    }
    $audience = $claims['aud'] ?? NULL;
    $audiences = is_array($audience) ? $audience : [$audience];
    $server = $context->authorizationServer;
    if (!in_array($server, $audiences, TRUE) && !in_array($server . '/', $audiences, TRUE)) {
      throw new InvalidCredentialException(sprintf('The credential is not for this authorization server: its "aud" must include %s.', $server));
    }
    $expires = self::numericDate($claims, 'exp');
    if ($expires === NULL || $context->now >= $expires + $context->clockSkew) {
      throw new InvalidCredentialException($expires === NULL ? 'The credential has no "exp".' : 'The credential has expired.');
    }
    $issued = self::numericDate($claims, 'iat');
    if ($issued === NULL || $issued > $context->now + $context->clockSkew) {
      throw new InvalidCredentialException($issued === NULL ? 'The credential has no "iat".' : 'The credential was issued in the future.');
    }
    $notBefore = self::numericDate($claims, 'nbf');
    if ($notBefore !== NULL && $context->now < $notBefore - $context->clockSkew) {
      throw new InvalidCredentialException('The credential is not valid yet.');
    }

    try {
      $document = $this->documents->resolve($subject);
    }
    catch (UnresolvableSubjectException $e) {
      throw new InvalidCredentialException($e->getMessage(), 0, $e);
    }
    if (($document['id'] ?? NULL) !== $subject) {
      throw new InvalidCredentialException('The document the subject dereferences to is not the subject\'s: its "id" differs.');
    }
    $method = VerificationMethods::select(VerificationMethods::authentication($document, $subject), $kid);
    if ($method === NULL) {
      throw new InvalidCredentialException('The "kid" names no verification method the subject controls and authenticates with.');
    }
    $inactive = $method->inactiveReason($context->now);
    if ($inactive !== NULL) {
      throw new InvalidCredentialException(sprintf('The verification method %s cannot be used: %s.', $method->id, $inactive));
    }
    if ($method->key->algorithm() !== $algorithm || !Jwt::verify($subjectToken, $method->key)) {
      throw new InvalidCredentialException('The credential\'s signature does not verify with the verification method its "kid" names.');
    }
    return new ValidatedCredential($subject, $subject, $subject, (int) floor($expires));
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
      throw new InvalidCredentialException(sprintf('The credential\'s "%s" is not a number.', $name));
    }
    return (float) $value;
  }

}
