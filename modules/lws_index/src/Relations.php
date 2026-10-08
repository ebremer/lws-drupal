<?php

declare(strict_types=1);

namespace Drupal\lws_index;

use Drupal\Core\Config\ConfigFactoryInterface;
use Ebremer\Lws\LinkRelation;

/**
 * The relations a type search may filter on (lws10-index).
 *
 * "type" always; besides it, the descriptive relations the site lists in
 * lws_index.settings:relations. They are never published: a filter on a
 * relation that is not searchable matches nothing, exactly as one on a
 * target nothing declares, so that searches cannot map the configuration.
 */
final class Relations {

  /**
   * Structural and protocol relations, which are never searchable.
   *
   * Registered relation types in lower case, and extension relations.
   */
  public const STRUCTURAL = [
    'acl',
    'alternate',
    'canonical',
    'collection',
    'create-form',
    'edit',
    'edit-form',
    'edit-media',
    'first',
    'item',
    'last',
    'linkset',
    'next',
    'prev',
    'previous',
    'self',
    'service',
    'service-desc',
    'service-doc',
    'service-meta',
    'storage',
    'type',
    'up',
    'http://www.w3.org/ns/ldp#contains',
    'https://www.w3.org/ns/lws#storage',
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Whether a search may filter on a relation.
   *
   * @param string $rel
   *   The relation, as Indexer::normalizeRel() gives it.
   */
  public function isSearchable(string $rel): bool {
    return $rel === LinkRelation::TYPE || in_array($rel, $this->descriptive(), TRUE);
  }

  /**
   * The descriptive relations the site lets searches filter on.
   *
   * @return list<string>
   *   Relations, normalized; structural ones are left out whatever the
   *   configuration says.
   */
  public function descriptive(): array {
    $relations = [];
    foreach ((array) $this->configFactory->get('lws_index.settings')->get('relations') as $rel) {
      $rel = Indexer::normalizeRel((string) $rel);
      if ($rel !== NULL && !self::isStructural($rel)) {
        $relations[] = $rel;
      }
    }
    return array_values(array_unique($relations));
  }

  /**
   * Whether a relation is structural or a protocol relation.
   *
   * @param string $rel
   *   The relation, as Indexer::normalizeRel() gives it.
   */
  public static function isStructural(string $rel): bool {
    return in_array($rel, self::STRUCTURAL, TRUE);
  }

}
