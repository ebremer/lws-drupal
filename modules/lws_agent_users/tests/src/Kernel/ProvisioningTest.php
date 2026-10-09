<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_agent_users\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\lws_agent_users\AgentUsers;
use Drupal\lws_agent_users\Form\AgentUsersSettingsForm;
use Drupal\lws_agent_users\Hook\LwsAgentUsersHooks;
use Drupal\user\Form\UserLoginForm;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests accounts made for agents, in provision mode.
 */
#[Group('lws_agent_users')]
#[RunTestsInSeparateProcesses]
final class ProvisioningTest extends AgentUsersKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->createRole([], 'agent', 'Agent');
    $this->createAdminRole('administrator', 'Administrator');
    $this->config('lws_agent_users.settings')
      ->set('mode', 'provision')
      ->set('provision.roles', ['agent', 'administrator', 'missing'])
      ->save();
  }

  /**
   * Tests the account an agent's first token makes, and that it is reused.
   */
  public function testProvision(): void {
    $this->allow($this->agentUsers->roleUri('agent'));
    $this->assertSame(200, $this->as(self::BOB, 'GET', '/lws/alice/root/notes')->getStatusCode(), 'Its first request is in its new roles.');
    $user = $this->agentUsers->userOf(self::BOB);
    $this->assertInstanceOf(UserInterface::class, $user);
    $this->assertSame((int) $user->id(), $this->currentUid());
    $this->assertSame('lws-agent-' . substr(hash('sha256', self::BOB), 0, 24), $user->getAccountName());
    $this->assertSame(self::BOB, $user->get(AgentUsers::URI_FIELD)->value);
    $this->assertTrue((bool) $user->get(AgentUsers::PROVISIONED_FIELD)->value);
    $this->assertNull($user->getEmail());
    $this->assertNull($user->getPassword());
    $this->assertTrue($user->isActive());
    // No administrator role, nor one that does not exist.
    $this->assertEqualsCanonicalizing(['authenticated', 'agent'], $user->getRoles());

    $this->assertSame(200, $this->as(self::BOB, 'GET', '/lws/alice/root/notes')->getStatusCode());
    $this->assertSame((int) $user->id(), $this->currentUid());
    $this->assertCount(2, $this->container->get('entity_type.manager')->getStorage('user')->loadMultiple());

    // A token that fails gets nothing.
    $this->send('GET', '/lws/alice/root/notes', ['Authorization' => 'Bearer ' . $this->token('https://carol.example/#me', ['exp' => time() - 60])]);
    $this->assertNull($this->agentUsers->userOf('https://carol.example/#me'));
  }

  /**
   * Tests the limits on which agents get accounts, and how many.
   */
  public function testLimits(): void {
    $settings = $this->config('lws_agent_users.settings');
    $settings->set('provision.issuers', ['https://other-as.example'])->save();
    $this->as(self::BOB, 'GET', '/lws/alice/root/notes');
    $this->assertNull($this->agentUsers->userOf(self::BOB), 'Only for tokens of the listed servers.');
    $settings->set('provision.issuers', [self::ISSUER])->set('provision.prefixes', ['https://idp.example/users/'])->save();
    $this->as(self::BOB, 'GET', '/lws/alice/root/notes');
    $this->assertNull($this->agentUsers->userOf(self::BOB), 'Only for agent URIs that start with a listed prefix.');
    $this->as('https://idp.example/users/1', 'GET', '/lws/alice/root/notes');
    $this->assertNotNull($this->agentUsers->userOf('https://idp.example/users/1'));

    $settings->set('provision.per_hour', 2)->save();
    $this->as('https://idp.example/users/2', 'GET', '/lws/alice/root/notes');
    $this->assertNotNull($this->agentUsers->userOf('https://idp.example/users/2'));
    $this->as('https://idp.example/users/3', 'GET', '/lws/alice/root/notes');
    $this->assertNull($this->agentUsers->userOf('https://idp.example/users/3'), 'At most so many an hour.');
    $this->assertSame(0, $this->currentUid());
    $this->assertSame(200, $this->as(self::ALICE, 'GET', '/lws/alice/root/notes')->getStatusCode(), 'Agents without an account go on as agents.');

    $settings->set('mode', 'link_only')->set('provision.per_hour', 100)->save();
    $this->as('https://idp.example/users/4', 'GET', '/lws/alice/root/notes');
    $this->assertNull($this->agentUsers->userOf('https://idp.example/users/4'));
  }

  /**
   * Tests the settings form.
   */
  public function testSettings(): void {
    $submit = function (array $values): array {
      $state = (new FormState())->setValues($values + [
        'mode' => 'provision',
        'issuers' => '',
        'prefixes' => '',
        'roles' => [],
        'per_hour' => '10',
        'prune_after_days' => '0',
      ]);
      $this->container->get('form_builder')->submitForm(AgentUsersSettingsForm::class, $state);
      return array_map('strval', $state->getErrors());
    };
    $form = $this->container->get('form_builder')->getForm(AgentUsersSettingsForm::class);
    $this->assertSame(['agent' => 'Agent'], $form['roles']['#options'], 'No administrator role is offered.');

    $this->assertSame([], $submit([
      'issuers' => "https://as.example\n\nhttps://as.example\nhttps://other.example ",
      'prefixes' => "https://idp.example/users/\ndid:key:",
      'roles' => ['agent' => 'agent'],
      'prune_after_days' => '30',
    ]));
    $settings = $this->config('lws_agent_users.settings');
    $this->assertSame('provision', $settings->get('mode'));
    $this->assertSame(['https://as.example', 'https://other.example'], $settings->get('provision.issuers'));
    $this->assertSame(['https://idp.example/users/', 'did:key:'], $settings->get('provision.prefixes'));
    $this->assertSame(['agent'], $settings->get('provision.roles'));
    $this->assertSame(10, $settings->get('provision.per_hour'));
    $this->assertSame(30, $settings->get('prune_after_days'));

    $this->assertArrayHasKey('issuers', $submit(['issuers' => 'not-a-url']));
    $this->assertArrayHasKey('prefixes', $submit(['prefixes' => 'no scheme']));
    $this->assertNotSame([], $submit(['per_hour' => '-1']));
    $this->assertNotSame([], $submit(['mode' => 'everyone']));
  }

  /**
   * Tests that an account made for an agent cannot log in.
   *
   * The login form's own checks are core's; this one comes after the
   * password is checked, and before the form refuses what it unset.
   */
  public function testLogin(): void {
    $this->as(self::BOB, 'GET', '/lws/alice/root/notes');
    $made = $this->agentUsers->userOf(self::BOB);
    $this->assertNotNull($made);
    $person = $this->createUser([], 'person');

    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $form = $this->container->get('form_builder')->getForm(UserLoginForm::class);
    $validate = is_array($form['#validate'] ?? NULL) ? $form['#validate'] : [];
    $refuse = [LwsAgentUsersHooks::class, 'refuseProvisioned'];
    $this->assertContains($refuse, $validate);
    $this->assertLessThan(array_search('::validateFinal', $validate, TRUE), array_search($refuse, $validate, TRUE));

    $passed = static function (UserInterface $user) use ($form): bool {
      $state = (new FormState())->set('uid', $user->id());
      $copy = $form;
      LwsAgentUsersHooks::refuseProvisioned($copy, $state);
      return (bool) $state->get('uid');
    };
    $this->assertFalse($passed($made));
    $this->assertTrue($passed($person));
  }

}
