<?php

declare(strict_types=1);

namespace Drupal\lws_notify\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\lws_authz\Server\SigningKeys;
use Drupal\lws_authz\Token\KeysUnavailableException;
use Drupal\lws_notify\Delivery\Deliverer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Status report entries for LWS notifications.
 */
final class LwsNotifyRequirements {

  use StringTranslationTrait;

  public function __construct(
    #[Autowire(service: 'lws_notify.signing_keys')]
    private readonly SigningKeys $keys,
    private readonly QueueFactory $queueFactory,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Whether PHP can finish sending a response before the request ends.
   *
   * Under PHP-FPM it can (fastcgi_finish_request()). As an Apache module it
   * cannot: a response without a body, such as a 204, or without a
   * Content-Length, is complete only when PHP is done.
   */
  public static function finishesResponsesEarly(): bool {
    return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
  }

  /**
   * Implements hook_runtime_requirements().
   *
   * @return array<string, array<string, mixed>>
   *   The requirements.
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    $requirements = ['lws_notify_signing_key' => $this->signingKey()];
    $delivery = $this->configFactory->get('lws_notify.settings')->get('delivery');
    if (!empty($delivery['inline']) && !self::finishesResponsesEarly()) {
      $requirements['lws_notify_inline'] = [
        'title' => $this->t('LWS notification delivery'),
        'value' => $this->t('At the end of each request, which PHP cannot finish early here'),
        'description' => $this->t('PHP runs as @sapi, which sends a response without a body, such as a 204 to PUT or DELETE, only once PHP is done: such responses wait while their notifications are delivered, for up to @budget seconds. Run PHP-FPM, or deliver on cron only and run the delivery queue often (<code>drush queue:run lws_notify_delivery</code>).', [
          '@sapi' => PHP_SAPI,
          '@budget' => (int) ($delivery['inline_budget'] ?? 10),
        ]),
        'severity' => RequirementSeverity::Warning,
      ];
    }
    $waiting = $this->queueFactory->get(Deliverer::QUEUE)->numberOfItems();
    $requirements['lws_notify_queue'] = [
      'title' => $this->t('LWS notification deliveries'),
      'value' => $this->formatPlural($waiting, '1 delivery waits for cron', '@count deliveries wait for cron'),
      'description' => $this->t('Retries, and notifications a request could not deliver in time, are delivered when cron runs.'),
      'severity' => $waiting > 1000 ? RequirementSeverity::Warning : RequirementSeverity::Info,
    ];
    return $requirements;
  }

  /**
   * The entry for the key that signs deliveries.
   *
   * @return array<string, mixed>
   *   The requirement.
   */
  private function signingKey(): array {
    $title = $this->t('LWS webhook signing key');
    try {
      $active = $this->keys->active();
    }
    catch (KeysUnavailableException $e) {
      return [
        'title' => $title,
        'value' => $this->t('None'),
        'description' => $this->t("Notifications are delivered unsigned, and inboxes that check signatures refuse them: @reason Set <code>\$settings['@setting']</code> in settings.php to a directory outside the web root, or configure the private file system.", [
          '@reason' => $e->getMessage(),
          '@setting' => $this->keys->directorySetting(),
        ]),
        'severity' => RequirementSeverity::Warning,
      ];
    }
    return [
      'title' => $title,
      'value' => $active['kid'],
      'description' => $this->t('Deliveries are signed with this key, which every storage description publishes. Rotate it with <code>drush lws:notify:key:rotate</code>.'),
      'severity' => RequirementSeverity::OK,
    ];
  }

}
