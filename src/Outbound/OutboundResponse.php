<?php

declare(strict_types=1);

namespace Drupal\lws\Outbound;

/**
 * The response to an outbound request, with its whole body.
 */
final class OutboundResponse {

  /**
   * Constructs a response.
   *
   * @param string $url
   *   The URL that answered, after any redirects.
   * @param int $status
   *   The HTTP status code.
   * @param array<array<string>> $headers
   *   The response headers.
   * @param string $body
   *   The response body.
   */
  public function __construct(
    public readonly string $url,
    public readonly int $status,
    public readonly array $headers,
    public readonly string $body,
  ) {}

  /**
   * The body as a JSON object.
   *
   * @return array<string, mixed>
   *   The members of the object.
   *
   * @throws \Drupal\lws\Outbound\OutboundHttpException
   *   When the response is not a 200 with a JSON object.
   */
  public function json(): array {
    if ($this->status !== 200) {
      throw new OutboundHttpException(sprintf('GET %s answered %d.', $this->url, $this->status));
    }
    try {
      $value = json_decode($this->body, TRUE, 64, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      throw new OutboundHttpException(sprintf('GET %s did not return JSON: %s', $this->url, $e->getMessage()), 0, $e);
    }
    if (!is_array($value) || ($value !== [] && array_is_list($value))) {
      throw new OutboundHttpException(sprintf('GET %s did not return a JSON object.', $this->url));
    }
    return $value;
  }

}
