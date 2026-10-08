<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Listing;

/**
 * One page of a container listing.
 *
 * Cursors are NULL where there is no such page, and the empty string for the
 * first page, whose URI is the container's own.
 */
final class ContainerPage {

  /**
   * Constructs a page.
   *
   * @param list<\Drupal\lws_storage\Entity\LwsResourceInterface> $members
   *   The members on the page, in name order.
   * @param int $total
   *   The members the agent may see, on all pages.
   * @param string|null $next
   *   The cursor of the next page.
   * @param string|null $prev
   *   The cursor of the previous page.
   * @param string|null $last
   *   The cursor of the last page.
   */
  public function __construct(
    public readonly array $members,
    public readonly int $total,
    public readonly ?string $next = NULL,
    public readonly ?string $prev = NULL,
    public readonly ?string $last = NULL,
  ) {}

}
