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
   * @param string|null $profile
   *   The profile the client asked for the type in, and the server produces
   *   it in (RFC 6906), such as the LWS profile; NULL for none.
   */
  public function __construct(
    public readonly string $type,
    public readonly ?string $profile = NULL,
  ) {}

  /**
   * The Content-Type value, naming the profile it was negotiated in.
   */
  public function contentType(): string {
    return $this->profile === NULL ? $this->type : $this->type . '; profile="' . $this->profile . '"';
  }

}
