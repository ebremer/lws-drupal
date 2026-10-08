<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_authz\Kernel;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\lws_authz\Entity\TrustedAuthorizationServerInterface;
use Drupal\lws_authz\Hook\LwsAuthzRequirements;
use Ebremer\Lws\Auth\SigningKey;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests trusted authorization servers: the entity, its form and its status.
 */
#[Group('lws')]
#[RunTestsInSeparateProcesses]
final class TrustedAuthorizationServerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'lws', 'lws_authz'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['lws', 'lws_authz']);
  }

  /**
   * Submits the add or edit form.
   *
   * @param array<string, mixed> $values
   *   The submitted values.
   * @param \Drupal\lws_authz\Entity\TrustedAuthorizationServerInterface|null $server
   *   The server to edit; NULL to add one.
   *
   * @return array<string, string>
   *   The validation errors, by element.
   */
  private function submit(array $values, ?TrustedAuthorizationServerInterface $server = NULL): array {
    $entityTypeManager = $this->container->get('entity_type.manager');
    $form = $entityTypeManager->getFormObject('lws_trusted_as', $server === NULL ? 'add' : 'edit');
    $form->setEntity($server ?? $entityTypeManager->getStorage('lws_trusted_as')->create());
    $formState = (new FormState())->setValues($values + ['op' => 'Save']);
    $this->container->get('form_builder')->submitForm($form, $formState);
    return array_map('strval', $formState->getErrors());
  }

  /**
   * Loads a server.
   */
  private function load(string $id): ?TrustedAuthorizationServerInterface {
    $server = $this->container->get('entity_type.manager')->getStorage('lws_trusted_as')->loadUnchanged($id);
    return $server instanceof TrustedAuthorizationServerInterface ? $server : NULL;
  }

  /**
   * Tests adding servers through the form.
   */
  public function testForm(): void {
    $key = SigningKey::generateP256();
    $errors = $this->submit([
      'label' => 'Main',
      'id' => 'main',
      'issuer' => 'https://as.example',
      // A private key: only its public members are stored.
      'jwks' => json_encode(['keys' => [$key->jwk() + ['kid' => 'k1']]]),
      'default' => 1,
    ]);
    $this->assertSame([], $errors);
    $server = $this->load('main');
    $this->assertNotNull($server);
    $this->assertSame('https://as.example', $server->getIssuer());
    $jwks = json_decode((string) $server->getJwks(), TRUE);
    $this->assertSame(['kty', 'crv', 'x', 'y', 'kid', 'alg', 'use'], array_keys($jwks['keys'][0]));
    $this->assertSame('main', $this->config('lws_authz.settings')->get('authorization_server'));

    // Without keys, they are discovered.
    $this->assertSame([], $this->submit([
      'label' => 'Other',
      'id' => 'other',
      'issuer' => 'https://other.example/tenant',
      'jwks' => '',
      'default' => NULL,
    ]));
    $this->assertNull($this->load('other')?->getJwks());
    $this->assertSame('main', $this->config('lws_authz.settings')->get('authorization_server'));

    // Unchecking "default" on the default server leaves storages with none.
    $values = ['label' => 'Main', 'issuer' => 'https://as.example', 'jwks' => '', 'default' => NULL];
    $this->assertSame([], $this->submit($values, $this->load('main')));
    $this->assertSame('', $this->config('lws_authz.settings')->get('authorization_server'));
  }

  /**
   * Tests the values the form refuses.
   */
  public function testFormValidation(): void {
    $bad = ['label' => 'Bad', 'id' => 'bad'];
    $errors = $this->submit($bad + ['issuer' => 'https://as.example/?tenant=1', 'jwks' => 'not JSON']);
    $this->assertArrayHasKey('issuer', $errors);
    $this->assertArrayHasKey('jwks', $errors);

    $rsa = '{"keys":[{"kty":"RSA","n":"AQAB","e":"AQAB"}]}';
    $errors = $this->submit($bad + ['issuer' => 'https://as.example', 'jwks' => $rsa]);
    $this->assertStringContainsString('no EC P-256', $errors['jwks']);
    $this->assertNull($this->load('bad'));
  }

  /**
   * Tests that deleting the default server leaves storages with none.
   */
  public function testDeleteDefault(): void {
    $this->submit(['label' => 'Main', 'id' => 'main', 'issuer' => 'https://as.example', 'jwks' => '', 'default' => 1]);
    $this->load('main')?->delete();
    $this->assertSame('', $this->config('lws_authz.settings')->get('authorization_server'));
  }

  /**
   * Tests the status report entry.
   */
  public function testRequirements(): void {
    $requirements = $this->container->get('class_resolver')->getInstanceFromDefinition(LwsAuthzRequirements::class);
    $this->assertSame(RequirementSeverity::Warning, $requirements->runtime()['lws_authz_default_server']['severity']);

    $this->submit(['label' => 'Main', 'id' => 'main', 'issuer' => 'https://as.example', 'jwks' => '', 'default' => 1]);
    $this->assertSame(RequirementSeverity::OK, $requirements->runtime()['lws_authz_default_server']['severity']);

    $this->config('lws_authz.settings')->set('authorization_server', 'gone')->save();
    $this->assertSame(RequirementSeverity::Error, $requirements->runtime()['lws_authz_default_server']['severity']);
  }

}
