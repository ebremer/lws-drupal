<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_notify\Kernel;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormState;
use Drupal\lws_notify\Form\NotificationSettingsForm;
use Drupal\lws_notify\Form\SubscriptionCancelForm;
use Drupal\lws_notify\Entity\LwsSubscriptionInterface;
use Drupal\lws_notify\Hook\LwsNotifyRequirements;
use Drupal\Tests\HttpKernelUiHelperTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the administration of notifications.
 */
#[Group('lws')]
#[RunTestsInSeparateProcesses]
final class AdminTest extends NotifyKernelTestBase {

  use HttpKernelUiHelperTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('user', ['users_data']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    // Installing a theme rebuilt the container.
    $this->installMocks();
    // User 1 is special; nobody here should be.
    $this->createUser();
  }

  /**
   * Submits a form with the form builder, as the current user.
   *
   * @param \Drupal\Core\Form\FormInterface|string $form
   *   The form object or class.
   * @param array<string, mixed> $values
   *   The submitted values.
   * @param mixed ...$arguments
   *   Arguments for the form's buildForm().
   *
   * @return list<string>
   *   The validation errors.
   */
  private function submit(FormInterface|string $form, array $values, mixed ...$arguments): array {
    $state = (new FormState())->setValues($values);
    $this->container->get('form_builder')->submitForm($form, $state, ...$arguments);
    return array_values(array_map('strval', $state->getErrors()));
  }

  /**
   * Tests the subscriptions page of a storage, and cancelling one.
   */
  public function testSubscriptionsPage(): void {
    $this->letRead(self::BOB, ['root/']);
    $response = $this->subscribe(self::ALICE, ['root/']);
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame(201, $this->subscribe(self::BOB, ['root/'], ['inbox' => 'https://inbox.example/bob'])->getStatusCode());
    $storage = $this->loadStorage('alice');
    $path = '/admin/content/lws/' . $storage->id() . '/subscriptions';

    $this->setCurrentUser($this->createUser(['access content']));
    $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(403);

    $this->setCurrentUser($this->createUser(['administer lws storages']));
    $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Subscriptions to Alice');
    $this->assertSession()->pageTextContains(self::ALICE . ' with https://app.example/id');
    $this->assertSession()->pageTextContains('https://inbox.example/bob');
    $this->assertSession()->pageTextContains(self::STORAGE . 'root/');
    $this->assertSession()->pageTextNotContains('Deactivated');

    $subscriptions = $this->container->get('entity_type.manager')->getStorage('lws_subscription')->loadByProperties(['agent' => self::BOB]);
    $bobs = reset($subscriptions);
    $this->assertInstanceOf(LwsSubscriptionInterface::class, $bobs);
    $bobs->set('active', FALSE)->set('last_status', 410)->set('failures', 1)->save();
    $this->drupalGet($path);
    $this->assertSession()->pageTextContains('Deactivated: its inbox last gave 410');

    $this->drupalGet($path . '/' . $bobs->id() . '/cancel');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Cancel the subscription of ' . self::BOB . ' to Alice?');
    $this->assertSame([], $this->submit(SubscriptionCancelForm::class, ['op' => 'Cancel subscription'], $storage, $bobs));
    $this->assertNull($this->container->get('entity_type.manager')->getStorage('lws_subscription')->loadUnchanged((int) $bobs->id()));

    // A subscription to another storage is not found under this one.
    $other = $this->storages->createStorage('other', 'Other', [self::ALICE]);
    $this->drupalGet('/admin/content/lws/' . $other->id() . '/subscriptions');
    $this->assertSession()->pageTextContains('Nobody has subscribed to this storage.');
    $alices = $this->container->get('entity_type.manager')->getStorage('lws_subscription')->loadByProperties(['agent' => self::ALICE]);
    $this->drupalGet('/admin/content/lws/' . $other->id() . '/subscriptions/' . key($alices) . '/cancel');
    $this->assertSession()->statusCodeEquals(404);
  }

  /**
   * Tests the settings form.
   */
  public function testSettings(): void {
    $this->setCurrentUser($this->createUser(['administer lws']));
    $this->drupalGet('/admin/config/services/lws/notifications');
    $this->assertSession()->statusCodeEquals(200);
    // As the base class sets them.
    $this->assertSession()->fieldValueEquals('retry_delays', '1, 60, 600');
    $this->assertSession()->fieldValueEquals('lifetime', '30');

    $values = [
      'include_actor' => 1,
      'subscriptions_per_agent' => 5,
      'topics' => 8,
      'lifetime' => 7,
      'inline' => NULL,
      'inline_budget' => 20,
      'timeout' => 3,
      'retry_delays' => ' 5,30 , 120',
      'max_failures' => 4,
    ];
    $this->assertSame([], $this->submit(NotificationSettingsForm::class, $values));
    $config = $this->config('lws_notify.settings');
    $this->assertTrue($config->get('include_actor'));
    $this->assertSame(604800, $config->get('limits.lifetime'));
    $this->assertSame([5, 30, 120], $config->get('delivery.retry_delays'));
    $this->assertFalse($config->get('delivery.inline'));
    $this->assertSame(4, $config->get('delivery.max_failures'));

    $errors = $this->submit(NotificationSettingsForm::class, ['retry_delays' => '5, soon'] + $values);
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('whole numbers of seconds', $errors[0]);
    $this->assertSame([], $this->submit(NotificationSettingsForm::class, ['retry_delays' => ''] + $values));
    $this->assertSame([], $this->config('lws_notify.settings')->get('delivery.retry_delays'));
  }

  /**
   * Tests the status report entries.
   */
  public function testStatusReport(): void {
    $hooks = $this->container->get(LwsNotifyRequirements::class);
    $this->assertInstanceOf(LwsNotifyRequirements::class, $hooks);
    $requirements = $hooks->runtime();
    $this->assertSame(RequirementSeverity::OK, $requirements['lws_notify_signing_key']['severity']);
    $this->assertSame(RequirementSeverity::Info, $requirements['lws_notify_queue']['severity']);
    // Tests run where PHP cannot finish a response early, as under mod_php.
    $this->assertFalse(LwsNotifyRequirements::finishesResponsesEarly());
    $this->assertSame(RequirementSeverity::Warning, $requirements['lws_notify_inline']['severity']);

    $this->config('lws_notify.settings')->set('delivery.inline', FALSE)->save();
    $this->setSetting('lws_notify_key_directory', '/proc/no-such-directory');
    $requirements = $hooks->runtime();
    $this->assertArrayNotHasKey('lws_notify_inline', $requirements);
    $this->assertSame(RequirementSeverity::Warning, $requirements['lws_notify_signing_key']['severity']);
    $this->assertStringContainsString('lws_notify_key_directory', (string) $requirements['lws_notify_signing_key']['description']);
  }

}
