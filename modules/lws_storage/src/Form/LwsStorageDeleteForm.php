<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Form;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lws_storage\Entity\LwsResourceInterface;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\StorageManager;

/**
 * Deletes a storage and everything in it.
 *
 * A small storage goes at once. A larger one is blocked first, so that it
 * answers 503 while it empties, and its resources are deleted in a batch.
 */
final class LwsStorageDeleteForm extends ContentEntityDeleteForm {

  /**
   * The most resources deleted at once, and in each step of a batch.
   */
  public const CHUNK = 200;

  /**
   * The storage being deleted.
   */
  private function storage(): LwsStorageInterface {
    $storage = $this->getEntity();
    assert($storage instanceof LwsStorageInterface);
    return $storage;
  }

  /**
   * The number of resources in a storage, its root included.
   */
  private static function count(int $storageId): int {
    return (int) \Drupal::entityTypeManager()->getStorage('lws_resource')->getQuery()
      ->accessCheck(FALSE)
      ->condition('storage', $storageId)
      ->count()
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->formatPlural(
      self::count((int) $this->storage()->id()) - 1,
      'Its one resource and its content will be deleted too, and so will its access policies, requests and grants. Clients will find nothing at its URIs. This cannot be undone.',
      'Its @count resources and their content will be deleted too, and so will its access policies, requests and grants. Clients will find nothing at its URIs. This cannot be undone.',
    );
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $storage = $this->storage();
    if (self::count((int) $storage->id()) <= self::CHUNK) {
      parent::submitForm($form, $form_state);
      return;
    }
    $storage->set('status', FALSE)->save();
    $batch = (new BatchBuilder())
      ->setTitle($this->t('Deleting the storage %label', ['%label' => (string) $storage->label()]))
      ->setProgressMessage('')
      ->addOperation([self::class, 'deleteResources'], [(int) $storage->id()])
      ->addOperation([self::class, 'deleteStorage'], [(int) $storage->id()])
      ->setFinishCallback([self::class, 'finished']);
    batch_set($batch->toArray());
    $form_state->setRedirectUrl($this->getRedirectUrl());
  }

  /**
   * Batch operation: deletes the resources of a storage, a chunk at a time.
   *
   * The deepest go first, so that no resource outlives its container.
   *
   * @param int $storageId
   *   The storage.
   * @param array<string, mixed>|\ArrayAccess<string, mixed> $context
   *   The batch context.
   */
  public static function deleteResources(int $storageId, array|\ArrayAccess &$context): void {
    $resources = \Drupal::entityTypeManager()->getStorage('lws_resource');
    if (!isset($context['sandbox']['total'])) {
      $context['sandbox']['total'] = max(1, self::count($storageId));
      $context['sandbox']['deleted'] = 0;
    }
    $ids = \Drupal::database()->select('lws_resource', 'r')
      ->fields('r', ['id'])
      ->condition('storage', $storageId)
      ->orderBy('depth', 'DESC')
      ->orderBy('id')
      ->range(0, self::CHUNK);
    $ids->addExpression('LENGTH([path]) - LENGTH(REPLACE([path], :slash, :empty))', 'depth', [
      ':slash' => '/',
      ':empty' => '',
    ]);
    $chunk = $resources->loadMultiple(array_map('intval', $ids->execute()?->fetchCol() ?? []));
    $queue = \Drupal::queue(StorageManager::GC_QUEUE);
    foreach ($chunk as $resource) {
      $fid = $resource instanceof LwsResourceInterface ? $resource->getContentFile()?->id() : NULL;
      if ($fid !== NULL) {
        $queue->createItem(['fid' => (int) $fid]);
      }
    }
    $resources->delete($chunk);
    $context['sandbox']['deleted'] += count($chunk);
    $context['finished'] = $chunk === [] ? 1 : min(0.99, $context['sandbox']['deleted'] / $context['sandbox']['total']);
    $context['message'] = new TranslatableMarkup('Deleted @deleted of @total resources.', [
      '@deleted' => $context['sandbox']['deleted'],
      '@total' => $context['sandbox']['total'],
    ]);
  }

  /**
   * Batch operation: deletes the storage once it is empty.
   *
   * @param int $storageId
   *   The storage.
   * @param array<string, mixed>|\ArrayAccess<string, mixed> $context
   *   The batch context.
   */
  public static function deleteStorage(int $storageId, array|\ArrayAccess &$context): void {
    $storage = \Drupal::entityTypeManager()->getStorage('lws_storage')->load($storageId);
    if ($storage instanceof LwsStorageInterface) {
      $context['results']['label'] = (string) $storage->label();
      $storage->delete();
      \Drupal::logger('lws')->notice('Deleted storage %label.', ['%label' => $storage->label()]);
    }
  }

  /**
   * Batch finish callback.
   *
   * @param bool $success
   *   Whether every operation ran.
   * @param array<string, mixed> $results
   *   The results of the operations.
   */
  public static function finished(bool $success, array $results): void {
    $messenger = \Drupal::messenger();
    if ($success && isset($results['label'])) {
      $messenger->addStatus(new TranslatableMarkup('Deleted the storage %label.', ['%label' => $results['label']]));
    }
    elseif (!$success) {
      $messenger->addError(new TranslatableMarkup('The storage could not be deleted completely. It is blocked; delete it again to finish.'));
    }
  }

}
