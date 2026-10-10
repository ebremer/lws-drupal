<?php

declare(strict_types=1);

namespace Drupal\lws_projection\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lws_projection\Form\ProjectionForm;
use Drupal\lws_projection\ProjectionListBuilder;

/**
 * A projection of Drupal content into a read-only LWS storage.
 *
 * DESIGN.md §7.4. Its storage, {prefix}/{slug}/, holds a container for each
 * entity type and bundle it projects, root/{entity type}/{bundle}/, and in
 * each a JSON data resource for each entity a visitor may see, named after
 * its ID. The Projector makes and keeps them.
 */
#[ConfigEntityType(
  id: 'lws_projection',
  label: new TranslatableMarkup('LWS projection'),
  label_collection: new TranslatableMarkup('LWS projections'),
  label_singular: new TranslatableMarkup('LWS projection'),
  label_plural: new TranslatableMarkup('LWS projections'),
  config_prefix: 'projection',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
  ],
  handlers: [
    'list_builder' => ProjectionListBuilder::class,
    'form' => [
      'add' => ProjectionForm::class,
      'edit' => ProjectionForm::class,
      'delete' => EntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/config/services/lws/projections',
    'add-form' => '/admin/config/services/lws/projections/add',
    'edit-form' => '/admin/config/services/lws/projections/{lws_projection}',
    'delete-form' => '/admin/config/services/lws/projections/{lws_projection}/delete',
  ],
  admin_permission: 'administer lws',
  label_count: [
    'singular' => '@count LWS projection',
    'plural' => '@count LWS projections',
  ],
  config_export: [
    'id',
    'label',
    'slug',
    'public',
    'bundles',
  ],
)]
class Projection extends ConfigEntityBase implements ProjectionInterface {

  /**
   * The machine name.
   */
  protected ?string $id = NULL;

  /**
   * The human-readable name.
   */
  protected ?string $label = NULL;

  /**
   * The slug of the storage it makes.
   */
  protected string $slug = '';

  /**
   * Whether anyone may read the storage.
   */
  protected bool $public = FALSE;

  /**
   * The content it projects.
   *
   * @var list<array{entity_type: string, bundle: string, type: string}>
   */
  protected array $bundles = [];

  /**
   * {@inheritdoc}
   */
  public function getSlug(): string {
    return $this->slug;
  }

  /**
   * {@inheritdoc}
   */
  public function isPublic(): bool {
    return $this->public;
  }

  /**
   * {@inheritdoc}
   */
  public function getBundles(): array {
    return $this->bundles;
  }

  /**
   * {@inheritdoc}
   */
  public function setBundles(array $bundles): static {
    $this->bundles = $bundles;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function covers(string $entityType, string $bundle): bool {
    foreach ($this->bundles as $item) {
      if ($item['entity_type'] === $entityType && $item['bundle'] === $bundle) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function coversType(string $entityType): bool {
    return in_array($entityType, array_column($this->bundles, 'entity_type'), TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function typeOf(string $entityType, string $bundle): ?string {
    foreach ($this->bundles as $item) {
      if ($item['entity_type'] === $entityType && $item['bundle'] === $bundle) {
        return $item['type'] === '' ? NULL : $item['type'];
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   *
   * It depends on the modules of the entity types it projects, and on the
   * bundles that are configuration, such as content types.
   */
  public function calculateDependencies() {
    parent::calculateDependencies();
    $entityTypeManager = $this->entityTypeManager();
    foreach ($this->bundles as $item) {
      $definition = $entityTypeManager->getDefinition($item['entity_type'], FALSE);
      if ($definition === NULL) {
        continue;
      }
      $this->addDependency('module', $definition->getProvider());
      $bundleType = $definition->getBundleEntityType();
      $bundle = $bundleType === NULL ? NULL : $entityTypeManager->getStorage($bundleType)->load($item['bundle']);
      if ($bundle !== NULL) {
        $this->addDependency('config', $bundle->getConfigDependencyName());
      }
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   *
   * A bundle, or a module, that goes takes its content out of the
   * projection, rather than the projection with it.
   *
   * @param array<string, array<mixed>> $dependencies
   *   The dependencies that go, by kind.
   */
  public function onDependencyRemoval(array $dependencies) {
    $changed = parent::onDependencyRemoval($dependencies);
    $entityTypeManager = $this->entityTypeManager();
    $removedConfig = array_map(static fn ($config): string => is_string($config) ? $config : $config->getConfigDependencyName(), $dependencies['config'] ?? []);
    $kept = [];
    foreach ($this->bundles as $item) {
      $definition = $entityTypeManager->getDefinition($item['entity_type'], FALSE);
      $gone = $definition === NULL || in_array($definition->getProvider(), $dependencies['module'] ?? [], TRUE);
      $bundleType = $definition?->getBundleEntityType();
      $bundleDefinition = $bundleType === NULL ? NULL : $entityTypeManager->getDefinition($bundleType, FALSE);
      if (!$gone && $bundleDefinition instanceof ConfigEntityTypeInterface) {
        $gone = in_array($bundleDefinition->getConfigPrefix() . '.' . $item['bundle'], $removedConfig, TRUE);
      }
      if ($gone) {
        $changed = TRUE;
      }
      else {
        $kept[] = $item;
      }
    }
    $this->bundles = $kept;
    return $changed;
  }

}
