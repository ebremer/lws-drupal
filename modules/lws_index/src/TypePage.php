<?php

declare(strict_types=1);

namespace Drupal\lws_index;

/**
 * One page of a type index.
 */
final class TypePage {

  /**
   * Constructs a page.
   *
   * @param list<string> $types
   *   The types on the page, in code point order.
   * @param int $total
   *   The types the agent may see, on all pages; for an agent whose view is
   *   filtered, those among the first that a count examines.
   * @param array{a: string|null, t: string|null, r: int}|null $next
   *   Where the next page starts: after type "a", and, when type "t" was
   *   being checked, after its resource "r"; NULL on the last page.
   */
  public function __construct(
    public readonly array $types,
    public readonly int $total,
    public readonly ?array $next,
  ) {}

}
