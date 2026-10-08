<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Linkset;

/**
 * The links of a resource that its clients manage (LWS Core §9.1).
 *
 * Server-managed relations (up, linkset, storage, the LWS class) are never
 * part of it: the server derives them.
 */
final class UserMetadata {

  /**
   * Constructs user metadata.
   *
   * @param list<string> $types
   *   The types declared with rel="type", other than LWS classes.
   * @param array<string, list<array<string, mixed>>> $links
   *   Other relations: targets by relation type, each an RFC 9264 target
   *   object with "href" and any target attributes.
   */
  public function __construct(
    public readonly array $types = [],
    public readonly array $links = [],
  ) {}

  /**
   * Whether there is no user metadata.
   */
  public function isEmpty(): bool {
    return $this->types === [] && $this->links === [];
  }

  /**
   * This metadata with another's types and targets added.
   *
   * Targets already present are not repeated.
   */
  public function with(UserMetadata $other): self {
    $types = array_values(array_unique([...$this->types, ...$other->types]));
    $links = $this->links;
    foreach ($other->links as $rel => $targets) {
      foreach ($targets as $target) {
        if (!in_array($target, $links[$rel] ?? [], TRUE)) {
          $links[$rel][] = $target;
        }
      }
    }
    return new self($types, $links);
  }

}
