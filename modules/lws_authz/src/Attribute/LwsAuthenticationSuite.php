<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines an LWS authentication suite (LWS Core §4).
 *
 * A suite validates one type of authentication credential, which a client
 * presents at the token endpoint as the subject token.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class LwsAuthenticationSuite extends Plugin {

  /**
   * Constructs the attribute.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The suite's name.
   * @param string $token_type
   *   The token type URI that identifies its credentials, as the
   *   "subject_token_type" of a token request (LWS Core §4.3).
   * @param list<string> $subject_identifier_types
   *   The subject identifier types it accepts, as the authorization server
   *   metadata lists them: a scheme such as "https", or "did:" followed by a
   *   DID method name.
   * @param class-string|null $deriver
   *   The deriver class, if any.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly string $token_type,
    public readonly array $subject_identifier_types = ['https'],
    public readonly ?string $deriver = NULL,
  ) {}

}
