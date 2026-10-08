<?php

declare(strict_types=1);

namespace Drupal\lws\Access;

/**
 * What a write would make of a resource, as far as the request says.
 *
 * Access policies may limit an action to resources of some formats or types
 * (LWS Core §11.3.5). A request that creates a resource, or that changes the
 * format or types of one, must satisfy them for what the resource becomes,
 * which only the request tells.
 */
final class ResourceChange {

  /**
   * Constructs a change.
   *
   * @param string|null $mediaType
   *   The media type the resource will have; NULL when it keeps its own, or
   *   is a container.
   * @param list<string>|null $types
   *   All the types it will have, its LWS class among them; NULL when it
   *   keeps its own.
   * @param bool $typesUnknown
   *   Whether the request may change its types in a way not known before the
   *   change is made, as a linkset write does.
   */
  public function __construct(
    public readonly ?string $mediaType = NULL,
    public readonly ?array $types = NULL,
    public readonly bool $typesUnknown = FALSE,
  ) {}

}
