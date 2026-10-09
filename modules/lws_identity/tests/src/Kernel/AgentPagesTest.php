<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_identity\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\lws_identity\Form\AgentKeyAddForm;
use Drupal\lws_identity\Form\AgentKeyDeleteForm;
use Ebremer\Lws\Auth\SigningKey;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests who may see an agent and manage its keys, and the key forms.
 */
#[Group('lws_identity')]
#[RunTestsInSeparateProcesses]
final class AgentPagesTest extends IdentityKernelTestBase {

  /**
   * Tests access to the pages.
   */
  public function testAccess(): void {
    $alice = $this->agentUser('alice', ['manage own lws agent keys']);
    $bob = $this->agentUser('bob', ['manage own lws agent keys']);
    $carol = $this->agentUser('carol');
    $admin = $this->createUser(['administer lws agents'], 'agents-admin');
    $aliceKey = $this->keys->add($alice, SigningKey::generateP256()->publicKey->jwk());
    $bobKey = $this->keys->add($bob, SigningKey::generateP256()->publicKey->jwk());

    $page = Url::fromRoute('lws_identity.user', ['user' => $alice->id()]);
    $add = Url::fromRoute('lws_identity.key_add', ['user' => $alice->id()]);
    $delete = Url::fromRoute('lws_identity.key_delete', ['user' => $alice->id(), 'lws_agent_key' => $aliceKey->id()]);
    $mismatched = Url::fromRoute('lws_identity.key_delete', ['user' => $alice->id(), 'lws_agent_key' => $bobKey->id()]);

    foreach ([$page, $add, $delete] as $url) {
      $this->assertTrue($url->access($alice), $url->getRouteName() . ' for its user');
      $this->assertTrue($url->access($admin), $url->getRouteName() . ' for an administrator');
      $this->assertFalse($url->access($bob), $url->getRouteName() . ' for another user');
    }
    // Another agent's key, through this one's page: no one.
    $this->assertFalse($mismatched->access($alice));
    $this->assertFalse($mismatched->access($admin));
    // Without the permission to manage keys, or without an agent.
    $this->assertFalse(Url::fromRoute('lws_identity.user', ['user' => $carol->id()])->access($carol));
    $alice->block()->save();
    $this->assertFalse($page->access($this->reload($alice)));
    $this->assertTrue($page->access($admin));
  }

  /**
   * Tests the page and the forms that add and remove keys.
   */
  public function testForms(): void {
    $alice = $this->agentUser('alice', ['manage own lws agent keys']);
    $this->setCurrentUser($alice);
    $page = $this->container->get('class_resolver')->getInstanceFromDefinition('\Drupal\lws_identity\Controller\AgentIdentityController')->page($alice);
    $this->assertStringContainsString($this->uris->uriOf($alice), (string) $page['agent']['#markup']);
    $this->assertSame([], $page['keys']['#rows']);

    $form = $this->container->get('form_builder');
    $public = SigningKey::generateP256()->publicKey->jwk();
    $state = $this->addition($public, 'Laptop', '2999-01-01');
    $form->submitForm(AgentKeyAddForm::class, $state, $alice);
    $this->assertSame([], $state->getErrors());
    $keys = $this->keys->keysOf((int) $alice->id());
    $this->assertCount(1, $keys);
    $this->assertSame('Laptop', $keys[0]->label());
    $this->assertSame(32472144000, $keys[0]->getExpires());

    // A private key is refused, and nothing is added.
    $state = $this->addition(SigningKey::generateP256()->jwk());
    $form->submitForm(AgentKeyAddForm::class, $state, $alice);
    $this->assertStringContainsString('private members', (string) ($state->getErrors()['jwk'] ?? ''));
    $state = $this->addition(SigningKey::generateP256()->publicKey->jwk(), '', '2001-01-01');
    $form->submitForm(AgentKeyAddForm::class, $state, $alice);
    $this->assertArrayHasKey('expires', $state->getErrors());
    $this->assertCount(1, $this->keys->keysOf((int) $alice->id()));

    $state = (new FormState())->setValues(['confirm' => 1]);
    $form->submitForm(AgentKeyDeleteForm::class, $state, $alice, $keys[0]);
    $this->assertSame([], $this->keys->keysOf((int) $alice->id()));
  }

  /**
   * The state of a submission of the form that adds a key.
   *
   * @param array<string, string> $jwk
   *   The JWK.
   * @param string $label
   *   The label.
   * @param string $expires
   *   The expiry date, if any.
   */
  private function addition(array $jwk, string $label = '', string $expires = ''): FormState {
    return (new FormState())->setValues([
      'jwk' => json_encode($jwk, JSON_THROW_ON_ERROR),
      'label' => $label,
      'expires' => $expires,
    ]);
  }

  /**
   * The account as it is stored now.
   */
  private function reload(AccountInterface $account): AccountInterface {
    $user = $this->container->get('entity_type.manager')->getStorage('user')->loadUnchanged($account->id());
    $this->assertInstanceOf(AccountInterface::class, $user);
    return $user;
  }

}
