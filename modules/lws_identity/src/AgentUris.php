<?php

declare(strict_types=1);

namespace Drupal\lws_identity;

use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws\Routing\LwsUrlParser;
use Drupal\user\UserInterface;

/**
 * The agent URIs of this site's users: {base URL}{prefix}/agents/{user UUID}.
 *
 * A UUID rather than the user ID or name, so that the URIs cannot be
 * enumerated and do not change when a user is renamed.
 */
final class AgentUris {

  /**
   * The segment under the LWS prefix (one of LwsUrlParser::RESERVED).
   */
  public const SEGMENT = 'agents';

  /**
   * A lower-case UUID, as Drupal generates them.
   */
  private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

  public function __construct(
    private readonly LwsUrlGenerator $urls,
    private readonly LwsUrlParser $parser,
  ) {}

  /**
   * What every agent URI of this site starts with.
   */
  public function base(): string {
    return $this->urls->baseUrl() . $this->parser->prefix() . '/' . self::SEGMENT . '/';
  }

  /**
   * The agent URI of a user.
   */
  public function uriOf(UserInterface $user): string {
    return $this->base() . $user->uuid();
  }

  /**
   * The user UUID an agent URI of this site names.
   *
   * @param string $uri
   *   An absolute URI, without a fragment.
   *
   * @return string|null
   *   The UUID, or NULL if the URI is not one of this site's agent URIs.
   */
  public function uuid(string $uri): ?string {
    $base = $this->base();
    if (!str_starts_with($uri, $base)) {
      return NULL;
    }
    $uuid = substr($uri, strlen($base));
    return preg_match(self::UUID, $uuid) === 1 ? $uuid : NULL;
  }

  /**
   * Whether a UUID has the form of the last segment of an agent URI.
   */
  public static function isUuid(string $uuid): bool {
    return preg_match(self::UUID, $uuid) === 1;
  }

}
