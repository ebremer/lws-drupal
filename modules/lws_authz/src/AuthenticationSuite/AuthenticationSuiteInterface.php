<?php

declare(strict_types=1);

namespace Drupal\lws_authz\AuthenticationSuite;

use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * An authentication suite: validates one type of authentication credential.
 */
interface AuthenticationSuiteInterface extends PluginInspectionInterface {

  /**
   * The token type URI of its credentials.
   */
  public function tokenType(): string;

  /**
   * The subject identifier types it accepts.
   *
   * @return list<string>
   *   Such as "https", "did:key" and "did:web".
   */
  public function subjectIdentifierTypes(): array;

  /**
   * Validates a credential presented as a subject token.
   *
   * @param string $subjectToken
   *   The subject token, as presented.
   * @param \Drupal\lws_authz\AuthenticationSuite\TokenExchangeContext $context
   *   The authorization server's identifier and the time.
   *
   * @return \Drupal\lws_authz\AuthenticationSuite\ValidatedCredential
   *   What the credential establishes.
   *
   * @throws \Drupal\lws_authz\AuthenticationSuite\InvalidCredentialException
   *   When the credential is not valid; its message may be shown to the
   *   client.
   */
  public function validate(string $subjectToken, TokenExchangeContext $context): ValidatedCredential;

}
