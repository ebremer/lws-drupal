<?php

declare(strict_types=1);

namespace Drupal\lws_projection\Form;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\lws\Routing\LwsUrlParser;
use Drupal\lws\Storage\StorageRegistryInterface;
use Drupal\lws_projection\Entity\Projection;
use Drupal\lws_projection\Entity\ProjectionInterface;
use Drupal\lws_projection\Projections;

/**
 * Adds or edits a projection.
 */
final class ProjectionForm extends EntityForm {

  public function __construct(
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected StorageRegistryInterface $storages,
    protected Projections $projections,
  ) {}

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $projection = $this->entity;
    assert($projection instanceof ProjectionInterface);

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $projection->label(),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $projection->id(),
      '#machine_name' => [
        'exists' => [Projection::class, 'load'],
      ],
      '#disabled' => !$projection->isNew(),
    ];
    $form['slug'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Storage'),
      '#description' => $this->t('The slug of the storage it makes, as in <em>/lws/{slug}/</em>: lower-case letters, digits and hyphens. It is fixed once the projection is made.'),
      '#default_value' => $projection->getSlug(),
      '#required' => TRUE,
      '#maxlength' => 63,
      '#disabled' => !$projection->isNew(),
    ];
    $form['public'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Anyone may read it'),
      '#description' => $this->t('Without a token. Otherwise only those its access policies allow, which its <em>Access</em> page manages.'),
      '#default_value' => $projection->isPublic(),
    ];
    $form['bundles'] = [
      '#type' => 'table',
      '#caption' => $this->t('The content it projects: each entity of these a visitor may view becomes a JSON resource in <em>root/{entity type}/{bundle}/</em>, with only the fields a visitor may view. A type URI, such as <em>https://schema.org/Article</em>, is declared as the type of its resources.'),
      '#header' => [$this->t('Project'), $this->t('Content'), $this->t('Type URI')],
      '#empty' => $this->t('There is no content to project.'),
    ];
    foreach ($this->options() as $key => $label) {
      [$type, $bundle] = explode(':', $key, 2);
      $row = [];
      $row['selected'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Project @label', ['@label' => $label]),
        '#title_display' => 'invisible',
        '#default_value' => $projection->covers($type, $bundle),
      ];
      $row['label'] = ['#markup' => $label];
      $row['type'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Type URI of @label', ['@label' => $label]),
        '#title_display' => 'invisible',
        '#default_value' => $projection->typeOf($type, $bundle) ?? '',
        '#size' => 40,
        '#maxlength' => 2048,
      ];
      $form['bundles'][$key] = $row;
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $projection = $this->entity;
    assert($projection instanceof ProjectionInterface);
    if ($projection->isNew()) {
      $slug = (string) $form_state->getValue('slug');
      if (preg_match(LwsUrlParser::SLUG, $slug) !== 1 || in_array($slug, LwsUrlParser::RESERVED, TRUE)) {
        $form_state->setErrorByName('slug', $this->t('The slug must be lower-case letters, digits and hyphens, start with a letter or digit, and not be one of %reserved.', ['%reserved' => implode(', ', LwsUrlParser::RESERVED)]));
      }
      elseif ($this->storages->get($slug) !== NULL || array_filter($this->projections->all(), static fn (ProjectionInterface $other): bool => $other->getSlug() === $slug) !== []) {
        $form_state->setErrorByName('slug', $this->t('There is a storage named %slug already.', ['%slug' => $slug]));
      }
    }
    $selected = 0;
    foreach ((array) $form_state->getValue('bundles') as $key => $row) {
      if (!is_array($row) || empty($row['selected'])) {
        continue;
      }
      $selected++;
      $uri = trim((string) ($row['type'] ?? ''));
      if ($uri !== '' && preg_match('/^[A-Za-z][A-Za-z0-9+.\-]*:[\x21-\x7E]+$/', $uri) !== 1) {
        $form_state->setErrorByName('bundles][' . $key . '][type', $this->t('%uri is not an absolute URI.', ['%uri' => $uri]));
      }
    }
    if ($selected === 0) {
      $form_state->setErrorByName('bundles', $this->t('Choose the content to project.'));
    }
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The projection.
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
    assert($entity instanceof ProjectionInterface);
    $entity->set('label', trim((string) $form_state->getValue('label')));
    if ($entity->isNew()) {
      $entity->set('id', (string) $form_state->getValue('id'));
      $entity->set('slug', (string) $form_state->getValue('slug'));
    }
    $entity->set('public', (bool) $form_state->getValue('public'));
    $bundles = [];
    foreach ((array) $form_state->getValue('bundles') as $key => $row) {
      if (is_array($row) && !empty($row['selected'])) {
        [$type, $bundle] = explode(':', (string) $key, 2);
        $bundles[] = ['entity_type' => $type, 'bundle' => $bundle, 'type' => trim((string) ($row['type'] ?? ''))];
      }
    }
    $entity->setBundles($bundles);
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $status = parent::save($form, $form_state);
    $projection = $this->entity;
    assert($projection instanceof ProjectionInterface);
    $this->messenger()->addStatus($this->t('Saved %label. Its storage, /lws/%slug/, is synced on cron, or now with <em>drush lws:projection:sync @id</em>.', [
      '%label' => (string) $projection->label(),
      '%slug' => $projection->getSlug(),
      '@id' => (string) $projection->id(),
    ]));
    $form_state->setRedirectUrl($projection->toUrl('collection'));
    return $status;
  }

  /**
   * The content that can be projected.
   *
   * The bundles of content entity types, but for LWS modules' own.
   *
   * @return array<string, string>
   *   Labels, by "{entity type}:{bundle}".
   */
  private function options(): array {
    $options = [];
    foreach ($this->entityTypeManager->getDefinitions() as $id => $definition) {
      if (!$definition instanceof ContentEntityTypeInterface || str_starts_with((string) $definition->getProvider(), 'lws')) {
        continue;
      }
      foreach ($this->bundleInfo->getBundleInfo($id) as $bundle => $info) {
        $options[$id . ':' . $bundle] = $definition->getLabel() . ': ' . ($info['label'] ?? $bundle);
      }
    }
    asort($options);
    return $options;
  }

}
