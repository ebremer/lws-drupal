<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\lws_authz\Policy\AccessPolicy;
use Drupal\lws_authz\Policy\PolicyStore;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\Form\ShareForm;

/**
 * The access page of a storage: who may do what in it (DESIGN.md §6.5).
 *
 * Its controllers may do anything, and need no policy. Everyone else may do
 * what the policies listed here permit.
 */
final class StorageAccessController implements ContainerInjectionInterface {

  use AutowireTrait;
  use StringTranslationTrait;

  public function __construct(
    private readonly PolicyStore $policies,
    private readonly FormBuilderInterface $formBuilder,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Who may manage a storage's access: administrators, and its owner.
   */
  public static function access(AccountInterface $account, LwsStorageInterface $lws_storage): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'administer lws storages')
      ->orIf(AccessResult::allowedIf(
        $account->hasPermission('manage own lws storages') && $account->id() > 0 && (int) $account->id() === $lws_storage->getOwnerId(),
      )->cachePerUser()->addCacheableDependency($lws_storage));
  }

  /**
   * The page title.
   */
  public function title(LwsStorageInterface $lws_storage): TranslatableMarkup {
    return $this->t('Access to @storage', ['@storage' => (string) $lws_storage->label()]);
  }

  /**
   * The page: the controllers, the policies, and the Share form.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function page(LwsStorageInterface $lws_storage): array {
    $build['controllers'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Controllers, who may do anything'),
      '#items' => $lws_storage->getControllers(),
      '#empty' => $this->t('None.'),
    ];
    $rows = [];
    foreach ($this->policies->forStorage((int) $lws_storage->id()) as $id => $entity) {
      $policy = $entity->toAccessPolicy();
      $rows[] = [
        self::assignee($policy->assignee),
        implode(', ', $policy->actions),
        [
          'data' => [
            '#theme' => 'item_list',
            '#items' => $policy->targetValues,
            '#prefix' => substr($policy->targetType, strlen('https://www.w3.org/ns/lws#')),
          ],
        ],
        implode('; ', array_map(static fn ($constraint): string => sprintf('%s %s %s', $constraint->leftOperand, $constraint->operator, implode(', ', (array) $constraint->rightOperand)), $policy->constraints)),
        $entity->getSource(),
        $this->dateFormatter->format((int) $entity->get('created')->value, 'short'),
        [
          'data' => [
            '#type' => 'operations',
            '#links' => [
              'delete' => [
                'title' => $this->t('Remove'),
                'url' => Url::fromRoute('lws_storage.access_delete', [
                  'lws_storage' => $lws_storage->id(),
                  'lws_policy' => $id,
                ]),
              ],
            ],
          ],
        ],
      ];
    }
    $build['policies'] = [
      '#type' => 'table',
      '#caption' => $this->t('Access policies'),
      '#header' => [
        $this->t('Who'),
        $this->t('May'),
        $this->t('In'),
        $this->t('If'),
        $this->t('Source'),
        $this->t('Made'),
        $this->t('Operations'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('Nobody but its controllers has access to this storage.'),
    ];
    $build['share'] = $this->formBuilder->getForm(ShareForm::class, $lws_storage);
    $build['#cache']['max-age'] = 0;
    return $build;
  }

  /**
   * How an assignee reads.
   */
  public static function assignee(string $assignee): string {
    return match ($assignee) {
      AccessPolicy::PUBLIC => (string) new TranslatableMarkup('Everyone'),
      AccessPolicy::AUTHENTICATED => (string) new TranslatableMarkup('Every authenticated agent'),
      default => $assignee,
    };
  }

}
