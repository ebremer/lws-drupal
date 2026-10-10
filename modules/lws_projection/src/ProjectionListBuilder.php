<?php

declare(strict_types=1);

namespace Drupal\lws_projection;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\lws_projection\Entity\ProjectionInterface;

/**
 * The projections.
 */
final class ProjectionListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The header row.
   */
  public function buildHeader(): array {
    return [
      'label' => $this->t('Name'),
      'storage' => $this->t('Storage'),
      'content' => $this->t('Content'),
      'readers' => $this->t('Readable by'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The row.
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof ProjectionInterface);
    $content = array_map(static fn (array $item): string => $item['entity_type'] . ':' . $item['bundle'], $entity->getBundles());
    return [
      'label' => $entity->label(),
      'storage' => '/lws/' . $entity->getSlug() . '/',
      'content' => implode(', ', $content),
      'readers' => $entity->isPublic() ? $this->t('Anyone') : $this->t('Those its policies allow'),
    ] + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function render(): array {
    $build = parent::render();
    $build['table']['#empty'] = $this->t('No content is projected. A projection makes a read-only storage of the nodes, media, terms or other content a visitor may view, as JSON.');
    return $build;
  }

}
