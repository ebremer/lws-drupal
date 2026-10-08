<?php

declare(strict_types=1);

namespace Drupal\lws\Http;

/**
 * A media type chosen by content negotiation.
 */
final class NegotiatedType {

  /**
   * Constructs a negotiated type.
   *
   * @param string $type
   *   The media type, without parameters.
   * @param array<string, string> $params
   *   The parameters of the Accept media range that named the type exactly,
   *   such as a JSON-LD profile.
   */
  public function __construct(
    public readonly string $type,
    public readonly array $params = [],
  ) {}

  /**
   * The Content-Type value, keeping a requested profile parameter.
   *
   * @param list<string> $profiles
   *   The profiles the representation conforms to.
   */
  public function contentType(array $profiles = []): string {
    $profile = $this->params['profile'] ?? NULL;
    if ($profile !== NULL && in_array($profile, $profiles, TRUE)) {
      return $this->type . '; profile="' . $profile . '"';
    }
    return $this->type;
  }

}
