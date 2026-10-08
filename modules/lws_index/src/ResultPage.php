<?php

declare(strict_types=1);

namespace Drupal\lws_index;

/**
 * One page of a type search's results.
 */
final class ResultPage {

  /**
   * Constructs a page.
   *
   * @param list<\Drupal\lws_storage\Entity\LwsResourceInterface> $resources
   *   The resources on the page, in the order they were created.
   * @param int $total
   *   The matching resources the agent may see, on all pages; for an agent
   *   whose view is filtered, those among the first that a count examines.
   * @param int|null $next
   *   The ID the next page starts after; NULL on the last page.
   */
  public function __construct(
    public readonly array $resources,
    public readonly int $total,
    public readonly ?int $next,
  ) {}

}
