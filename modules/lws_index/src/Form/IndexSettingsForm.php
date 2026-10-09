<?php

declare(strict_types=1);

namespace Drupal\lws_index\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\lws_index\Indexer;
use Drupal\lws_index\Query\FilterParser;
use Drupal\lws_index\Relations;

/**
 * Settings of the type index: relations to filter on, types from content.
 */
final class IndexSettingsForm extends ConfigFormBase {

  /**
   * A registered relation type (RFC 8288 §2.1.1), in lower case.
   */
  private const REGISTERED = '/^[a-z][a-z0-9.\-]*$/';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lws_index_settings';
  }

  /**
   * {@inheritdoc}
   *
   * @return list<string>
   *   The configuration names.
   */
  protected function getEditableConfigNames(): array {
    return ['lws_index.settings'];
  }

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
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['relations'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Relations searches may filter on'),
      '#description' => $this->t('Besides type, the descriptive link relations a type search may filter on, one per line: registered relation types, such as describedby, or extension relations, as URIs. Clients are never told which they are: a filter on any other relation finds nothing. Structural relations, such as up or self, cannot be listed. Every relation of every resource is indexed, so a change here applies at once.'),
      '#rows' => 8,
      '#config_target' => new ConfigTarget(
        'lws_index.settings',
        'relations',
        fromConfig: static fn (?array $relations): string => implode("\n", $relations ?? []),
        toConfig: static fn (?string $text): array => self::relations((string) $text),
      ),
    ];
    $form['content_types'] = [
      '#type' => 'details',
      '#title' => $this->t('Types read from content'),
      '#open' => TRUE,
      'enabled' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Read the types Turtle and N-Triples content states about the resource itself'),
        '#description' => $this->t('Besides the types clients declare in Link headers, the index then holds those a resource&rsquo;s content gives it, such as <code>&lt;&gt; a &lt;https://schema.org/Note&gt; .</code>, and searches find it by them. The content is parsed as each resource is saved. Run <code>drush lws:index:rebuild</code> after a change here, so that the resources stored before it are read, or no longer.'),
        '#config_target' => 'lws_index.settings:content_types.enabled',
      ],
      'max_bytes' => [
        '#type' => 'number',
        '#title' => $this->t('Largest content read'),
        '#description' => $this->t('In bytes. Larger content states no types.'),
        '#min' => 0,
        '#field_suffix' => $this->t('bytes'),
        '#config_target' => 'lws_index.settings:content_types.max_bytes',
      ],
    ];
    return parent::buildForm($form, $form_state);
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
    foreach (self::relations((string) $form_state->getValue('relations')) as $rel) {
      if (Relations::isStructural($rel)) {
        $form_state->setErrorByName('relations', $this->t('%rel is a structural relation, which searches may not filter on.', ['%rel' => $rel]));
      }
      elseif (preg_match(self::REGISTERED, $rel) !== 1 && !(str_contains($rel, ':') && FilterParser::isIri($rel))) {
        $form_state->setErrorByName('relations', $this->t('%rel is neither a relation type nor a URI.', ['%rel' => $rel]));
      }
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * Reads a list of relations, one per line.
   *
   * @return list<string>
   *   The relations as the index holds them, each once; lines that cannot be
   *   one are kept as they are, for validation to report.
   */
  private static function relations(string $text): array {
    $relations = [];
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
      $line = trim($line);
      if ($line !== '') {
        $relations[] = Indexer::normalizeRel($line) ?? $line;
      }
    }
    return array_values(array_unique($relations));
  }

}
