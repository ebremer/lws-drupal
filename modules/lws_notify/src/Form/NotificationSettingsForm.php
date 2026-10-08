<?php

declare(strict_types=1);

namespace Drupal\lws_notify\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings of notifications: what they say, the limits, and delivery.
 */
final class NotificationSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lws_notify_settings';
  }

  /**
   * {@inheritdoc}
   *
   * @return list<string>
   *   The configuration names.
   */
  protected function getEditableConfigNames(): array {
    return ['lws_notify.settings'];
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
    $form['include_actor'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Name who made each change'),
      '#description' => $this->t('Notifications then tell subscribers which agent made a change. Off by default, as the LWS core asks: a subscriber may read a resource without being entitled to know who else writes it.'),
      '#config_target' => 'lws_notify.settings:include_actor',
    ];
    $form['limits'] = [
      '#type' => 'details',
      '#title' => $this->t('Limits'),
      '#open' => TRUE,
    ];
    $form['limits']['subscriptions_per_agent'] = [
      '#type' => 'number',
      '#title' => $this->t('Subscriptions per agent'),
      '#description' => $this->t('The live subscriptions one agent may hold at one storage; more answers 429.'),
      '#min' => 1,
      '#config_target' => 'lws_notify.settings:limits.subscriptions_per_agent',
      '#required' => TRUE,
    ];
    $form['limits']['topics'] = [
      '#type' => 'number',
      '#title' => $this->t('Topics per subscription'),
      '#min' => 1,
      '#config_target' => 'lws_notify.settings:limits.topics',
      '#required' => TRUE,
    ];
    $form['limits']['lifetime'] = [
      '#type' => 'number',
      '#title' => $this->t('Longest lifetime, in days'),
      '#description' => $this->t('A subscription ends after this long at most, and one that asks for no end gets this one. 0 for no limit.'),
      '#min' => 0,
      '#config_target' => new ConfigTarget(
        'lws_notify.settings',
        'limits.lifetime',
        fromConfig: static fn (?int $seconds): int => intdiv((int) $seconds, 86400),
        toConfig: static fn (mixed $days): int => (int) $days * 86400,
      ),
      '#required' => TRUE,
    ];
    $form['delivery'] = [
      '#type' => 'details',
      '#title' => $this->t('Delivery'),
      '#open' => TRUE,
    ];
    $form['delivery']['inline'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Deliver at the end of the request'),
      '#description' => $this->t('Notifications go out as soon as the response to the change is sent. Otherwise they all wait for cron.'),
      '#config_target' => 'lws_notify.settings:delivery.inline',
    ];
    $form['delivery']['inline_budget'] = [
      '#type' => 'number',
      '#title' => $this->t('Time for delivering at the end of a request, in seconds'),
      '#description' => $this->t('What is not delivered by then waits for cron.'),
      '#min' => 1,
      '#max' => 60,
      '#config_target' => 'lws_notify.settings:delivery.inline_budget',
      '#required' => TRUE,
    ];
    $form['delivery']['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Timeout of one delivery, in seconds'),
      '#min' => 1,
      '#max' => 30,
      '#config_target' => 'lws_notify.settings:delivery.timeout',
      '#required' => TRUE,
    ];
    $form['delivery']['retry_delays'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Retry delays, in seconds'),
      '#description' => $this->t('How long to wait before each retry of a delivery the inbox could not take (5xx, 429 or no answer), separated by commas. A first retry of at most 5 seconds is made at the end of the request; later ones when cron runs. Empty for no retries.'),
      '#config_target' => new ConfigTarget(
        'lws_notify.settings',
        'delivery.retry_delays',
        fromConfig: static fn (?array $delays): string => implode(', ', $delays ?? []),
        toConfig: static fn (?string $text): array => self::delays((string) $text) ?? [],
      ),
    ];
    $form['delivery']['max_failures'] = [
      '#type' => 'number',
      '#title' => $this->t('Failed deliveries before a subscription is deactivated'),
      '#description' => $this->t('Deliveries in a row, each after its retries. An inbox that answers 410 Gone deactivates its subscription at once.'),
      '#min' => 1,
      '#config_target' => 'lws_notify.settings:delivery.max_failures',
      '#required' => TRUE,
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
    if (self::delays((string) $form_state->getValue('retry_delays')) === NULL) {
      $form_state->setErrorByName('retry_delays', $this->t('Give the delays as whole numbers of seconds, separated by commas, such as 2, 60, 600.'));
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * Reads a list of delays.
   *
   * @return list<int>|null
   *   The delays, or NULL if the text is not a list of positive integers.
   */
  private static function delays(string $text): ?array {
    if (trim($text) === '') {
      return [];
    }
    $delays = [];
    foreach (explode(',', $text) as $part) {
      $part = trim($part);
      if (preg_match('/^[1-9][0-9]{0,6}$/', $part) !== 1) {
        return NULL;
      }
      $delays[] = (int) $part;
    }
    return $delays;
  }

}
