<?php

declare(strict_types=1);

namespace Drupal\lws\Outbound;

use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;

/**
 * Fetches documents named by untrusted input, such as an issuer's JWKS.
 *
 * The URLs come from tokens and from documents on other servers, so each
 * request is checked before it is sent (DESIGN.md §6.4):
 *
 * - HTTPS only;
 * - the host must resolve to globally routable addresses only, and cURL is
 *   pinned to the addresses that were checked, so DNS cannot answer
 *   differently for the connection (DNS rebinding);
 * - redirects are followed by hand, at most three, each checked the same way;
 * - bodies are capped at 256 KiB, and requests time out after 5 seconds.
 *
 * Origins listed in $settings['lws_outbound_allowlist'], such as
 * "http://localhost:8080", skip the scheme and address checks. It is meant for
 * development and tests.
 */
final class OutboundHttp {

  /**
   * The largest body accepted, in bytes.
   */
  public const MAX_BYTES = 262144;

  /**
   * The redirects followed, at most.
   */
  public const MAX_REDIRECTS = 3;

  /**
   * The connect and total timeout, in seconds.
   */
  public const TIMEOUT = 5;

  public function __construct(
    private readonly ClientInterface $client,
    private readonly HostResolverInterface $resolver,
    private readonly Settings $settings,
  ) {}

  /**
   * Fetches a URL with GET.
   *
   * @param string $url
   *   An absolute URL.
   * @param string $accept
   *   The Accept header value.
   *
   * @throws \Drupal\lws\Outbound\OutboundHttpException
   *   When the URL or a redirect is refused, or the request fails.
   */
  public function get(string $url, string $accept = 'application/json'): OutboundResponse {
    for ($redirects = 0;; $redirects++) {
      $response = $this->send($url, $accept);
      $status = $response->getStatusCode();
      if ($status < 300 || $status >= 400 || !$response->hasHeader('Location')) {
        return new OutboundResponse($url, $status, $response->getHeaders(), (string) $response->getBody());
      }
      if ($redirects === self::MAX_REDIRECTS) {
        throw new OutboundHttpException(sprintf('GET %s: more than %d redirects.', $url, self::MAX_REDIRECTS));
      }
      $url = (string) UriResolver::resolve(new Uri($url), new Uri($response->getHeaderLine('Location')));
    }
  }

  /**
   * Sends one request, without following redirects.
   */
  private function send(string $url, string $accept): ResponseInterface {
    $options = [
      'headers' => ['Accept' => $accept],
      'allow_redirects' => FALSE,
      'http_errors' => FALSE,
      'timeout' => self::TIMEOUT,
      'connect_timeout' => self::TIMEOUT,
      'on_headers' => static function (ResponseInterface $response) use ($url): void {
        if ((int) $response->getHeaderLine('Content-Length') > self::MAX_BYTES) {
          throw new OutboundHttpException(sprintf('GET %s: the body is larger than %d bytes.', $url, self::MAX_BYTES));
        }
      },
    ];
    // A connection kept alive by an earlier request to the same host, by this
    // or any other code using the shared client, would bypass the pin.
    $options['curl'] = [CURLOPT_FRESH_CONNECT => TRUE, CURLOPT_FORBID_REUSE => TRUE];
    $pin = $this->check($url);
    if ($pin !== NULL) {
      $options['curl'][CURLOPT_RESOLVE] = [$pin];
    }
    $sink = new CappedStream(Utils::streamFor(fopen('php://temp', 'w+b')), self::MAX_BYTES);
    $options['sink'] = $sink;
    try {
      $response = $this->client->request('GET', $url, $options);
    }
    catch (GuzzleException $e) {
      if ($sink->overflowed) {
        throw new OutboundHttpException(sprintf('GET %s: the body is larger than %d bytes.', $url, self::MAX_BYTES), 0, $e);
      }
      $previous = $e->getPrevious();
      throw $previous instanceof OutboundHttpException ? $previous : new OutboundHttpException(sprintf('GET %s failed: %s', $url, $e->getMessage()), 0, $e);
    }
    if ($sink->overflowed) {
      throw new OutboundHttpException(sprintf('GET %s: the body is larger than %d bytes.', $url, self::MAX_BYTES));
    }
    return $response;
  }

  /**
   * Checks a URL before it is fetched.
   *
   * @return string|null
   *   The CURLOPT_RESOLVE entry that pins the host to its checked addresses;
   *   NULL for an IP address or an allow-listed origin.
   *
   * @throws \Drupal\lws\Outbound\OutboundHttpException
   *   When the URL must not be fetched.
   */
  private function check(string $url): ?string {
    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($host === '' || isset($parts['user']) || isset($parts['pass'])) {
      throw new OutboundHttpException(sprintf('GET %s: not an absolute URL with a host and no user information.', $url));
    }
    $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    $origin = $scheme . '://' . $host . (isset($parts['port']) ? ':' . $port : '');
    if (in_array($origin, (array) $this->settings->get('lws_outbound_allowlist', []), TRUE)) {
      return NULL;
    }
    if ($scheme !== 'https') {
      throw new OutboundHttpException(sprintf('GET %s: only HTTPS URLs are fetched.', $url));
    }

    $name = trim($host, '[]');
    $literal = filter_var($name, FILTER_VALIDATE_IP) !== FALSE;
    $addresses = $literal ? [$name] : $this->resolver->resolve($name);
    if ($addresses === []) {
      throw new OutboundHttpException(sprintf('GET %s: %s does not resolve.', $url, $name));
    }
    foreach ($addresses as $address) {
      if (!PublicAddress::isPublic($address)) {
        throw new OutboundHttpException(sprintf('GET %s: %s resolves to %s, which is not a public address.', $url, $name, $address));
      }
    }
    if ($literal) {
      return NULL;
    }
    $pinned = array_map(static fn (string $address): string => str_contains($address, ':') ? '[' . $address . ']' : $address, $addresses);
    return $name . ':' . $port . ':' . implode(',', $pinned);
  }

}
