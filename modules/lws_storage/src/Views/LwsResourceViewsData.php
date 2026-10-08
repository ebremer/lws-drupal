<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Views;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\EntityViewsData;

/**
 * Views data of resources: what core gives, and their content files.
 *
 * Core relates entity reference base fields to their targets, but not file
 * fields, whose column has another name.
 */
final class LwsResourceViewsData extends EntityViewsData {

  /**
   * {@inheritdoc}
   *
   * @return array<string, array<string, mixed>>
   *   The Views data.
   */
  public function getViewsData(): array {
    $data = parent::getViewsData();
    $data['lws_resource']['content__target_id']['relationship'] = [
      'title' => new TranslatableMarkup('Content file'),
      'label' => new TranslatableMarkup('Content file'),
      'help' => new TranslatableMarkup('The file that holds the content of a data resource.'),
      'base' => 'file_managed',
      'base field' => 'fid',
      'id' => 'standard',
    ];
    return $data;
  }

}
