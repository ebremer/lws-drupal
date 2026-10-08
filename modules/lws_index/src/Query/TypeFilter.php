<?php

declare(strict_types=1);

namespace Drupal\lws_index\Query;

use Drupal\lws_index\Indexer;

/**
 * A type search filter, in conjunctive normal form (lws10-index).
 *
 * Each group belongs to one relation, "type" among them, and holds IRIs of
 * which a resource must declare at least one for that relation; a resource
 * matches when it satisfies every group. No group matches everything.
 * Groups are kept sorted and without duplicates, so equal filters have equal
 * canonical forms.
 */
final class TypeFilter {

  /**
   * The groups.
   *
   * @var list<array{rel: string, hrefs: list<string>}>
   */
  public readonly array $groups;

  /**
   * Constructs a filter.
   *
   * @param list<array{rel: string, hrefs: list<string>}> $groups
   *   The groups; duplicates are ignored.
   */
  public function __construct(array $groups) {
    $unique = [];
    foreach ($groups as $group) {
      $hrefs = array_values(array_unique($group['hrefs']));
      sort($hrefs, SORT_STRING);
      $key = json_encode([$group['rel'], $hrefs], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
      $unique[$key] = ['rel' => $group['rel'], 'hrefs' => $hrefs];
    }
    ksort($unique, SORT_STRING);
    $this->groups = array_values($unique);
  }

  /**
   * Whether the filter has no group, and so matches every resource.
   */
  public function isEmpty(): bool {
    return $this->groups === [];
  }

  /**
   * The relations the filter constrains.
   *
   * @return list<string>
   *   Relations, each once.
   */
  public function relations(): array {
    return array_values(array_unique(array_column($this->groups, 'rel')));
  }

  /**
   * The index hashes of each group.
   *
   * @return list<list<string>>
   *   For each group, the hashes of which a resource must hold one.
   */
  public function hashes(): array {
    return array_map(
      static fn (array $group): array => array_map(static fn (string $href): string => Indexer::hash($group['rel'], $href), $group['hrefs']),
      $this->groups,
    );
  }

  /**
   * The filter as plain data, for a pagination cursor.
   *
   * @return list<array{0: string, 1: list<string>}>
   *   Each group as its relation and IRIs.
   */
  public function toArray(): array {
    return array_map(static fn (array $group): array => [$group['rel'], $group['hrefs']], $this->groups);
  }

  /**
   * Rebuilds a filter from toArray().
   *
   * @param mixed $data
   *   The data.
   *
   * @return self|null
   *   The filter; NULL if the data is not one.
   */
  public static function fromArray(mixed $data): ?self {
    if (!is_array($data) || !array_is_list($data)) {
      return NULL;
    }
    $groups = [];
    foreach ($data as $group) {
      if (!is_array($group) || count($group) !== 2 || !is_string($group[0] ?? NULL) || !is_array($group[1] ?? NULL) || !array_is_list($group[1]) || $group[1] === []) {
        return NULL;
      }
      foreach ($group[1] as $href) {
        if (!is_string($href)) {
          return NULL;
        }
      }
      /** @var list<string> $hrefs */
      $hrefs = $group[1];
      $groups[] = ['rel' => $group[0], 'hrefs' => $hrefs];
    }
    return new self($groups);
  }

}
