<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\lws_storage\Listing\ContainerPager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings of every storage: where content goes, and the limits.
 */
final class StorageSettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected StreamWrapperManagerInterface $streamWrappers,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('stream_wrapper_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lws_storage_settings';
  }

  /**
   * {@inheritdoc}
   *
   * @return list<string>
   *   The configuration names.
   */
  protected function getEditableConfigNames(): array {
    return ['lws_storage.settings'];
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
    $schemes = array_map('strval', $this->streamWrappers->getNames(StreamWrapperInterface::WRITE_VISIBLE));
    $current = (string) $this->config('lws_storage.settings')->get('scheme');
    if (!isset($schemes[$current])) {
      $schemes[$current] = $this->t('@scheme (not available)', ['@scheme' => $current]);
    }
    $form['scheme'] = [
      '#type' => 'radios',
      '#title' => $this->t('Content file system'),
      '#description' => $this->t('Where the content of data resources is kept. Content written before a change stays where it is. Use one that the web server does not serve directly, such as the private file system.'),
      '#options' => $schemes,
      '#config_target' => 'lws_storage.settings:scheme',
      '#required' => TRUE,
    ];
    $form['max_upload_bytes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Largest content'),
      '#description' => $this->t("The largest content one request may write, such as <em>100 MB</em>; more answers 413 Content Too Large. Empty leaves it to PHP's post_max_size, which limits only POST, and to the web server."),
      '#size' => 12,
      '#config_target' => new ConfigTarget(
        'lws_storage.settings',
        'max_upload_bytes',
        fromConfig: static fn (?int $bytes): string => $bytes ? ByteSize::format($bytes) : '',
        toConfig: static fn (?string $text): int => ByteSize::parse((string) $text) ?? 0,
      ),
    ];
    $form['max_recursive_delete'] = [
      '#type' => 'number',
      '#title' => $this->t('Largest recursive delete'),
      '#description' => $this->t('The most resources one delete of a container with "Depth: infinity" may remove; more answers 422. 0 for no limit, which lets one request take as long as the container is large.'),
      '#min' => 0,
      '#config_target' => 'lws_storage.settings:max_recursive_delete',
      '#required' => TRUE,
    ];
    $form['page_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Page size'),
      '#description' => $this->t('The members on one page of a container listing, for storages that set none.'),
      '#min' => 1,
      '#max' => ContainerPager::MAX_PAGE_SIZE,
      '#config_target' => 'lws_storage.settings:page_size',
      '#required' => TRUE,
    ];
    $form['require_if_match'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require If-Match'),
      '#description' => $this->t('Replacing, patching and deleting need If-Match, so that clients cannot overwrite changes they have not seen; without it they answer 428 Precondition Required. Storages can choose otherwise.'),
      '#config_target' => 'lws_storage.settings:require_if_match',
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
    $size = trim((string) $form_state->getValue('max_upload_bytes'));
    if ($size !== '' && ByteSize::parse($size) === NULL) {
      $form_state->setErrorByName('max_upload_bytes', $this->t('Give the size as a number of bytes, or such as 100 MB.'));
    }
    parent::validateForm($form, $form_state);
  }

}
