<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_authz\Kernel;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\lws_authz\Entity\TrustedAuthorizationServerInterface;
use Drupal\lws_authz\Form\AuthorizationSettingsForm;
use Drupal\lws_authz\Hook\LwsAuthzRequirements;
use Ebremer\Lws\Auth\SigningKey;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests trusted authorization servers: the entity, its form and its status.
 *
 * And the settings, which choose between them and this site's own server.
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

    // Unchecking "default" on the default server gives storages this site's.
    $values = ['label' => 'Main', 'issuer' => 'https://as.example', 'jwks' => '', 'default' => NULL];
    $this->assertSame([], $this->submit($values, $this->load('main')));
    $this->assertSame('local', $this->config('lws_authz.settings')->get('authorization_server'));
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
    $this->assertStringContainsString('no signing keys of a kind this site verifies', $errors['jwks']);
    $this->assertNull($this->load('bad'));

    // "local" names this site's own server.
    $errors = $this->submit(['label' => 'Local', 'id' => 'local', 'issuer' => 'https://as.example', 'jwks' => '']);
    $this->assertArrayHasKey('id', $errors);
    $this->assertNull($this->load('local'));
  }

  /**
   * Tests that deleting the default server makes this site's the default.
   */
  public function testDeleteDefault(): void {
    $this->submit(['label' => 'Main', 'id' => 'main', 'issuer' => 'https://as.example', 'jwks' => '', 'default' => 1]);
    $this->load('main')?->delete();
    $this->assertSame('local', $this->config('lws_authz.settings')->get('authorization_server'));
  }

  /**
   * Tests the status report entry.
   */
  public function testRequirements(): void {
    $requirements = $this->container->get('class_resolver')->getInstanceFromDefinition(LwsAuthzRequirements::class);
    // This site's server is the default, and has no key directory.
    $runtime = $requirements->runtime();
    $this->assertSame(RequirementSeverity::Error, $runtime['lws_authz_default_server']['severity']);
    $this->assertSame(RequirementSeverity::Warning, $runtime['lws_authz_local_server']['severity']);

    $this->submit(['label' => 'Main', 'id' => 'main', 'issuer' => 'https://as.example', 'jwks' => '', 'default' => 1]);
    $runtime = $requirements->runtime();
    $this->assertSame(RequirementSeverity::OK, $runtime['lws_authz_default_server']['severity']);

    $this->config('lws_authz.settings')->set('authorization_server', 'gone')->save();
    $runtime = $requirements->runtime();
    $this->assertSame(RequirementSeverity::Error, $runtime['lws_authz_default_server']['severity']);

    $this->setSetting('lws_authz_key_directory', $this->siteDirectory . '/keys');
    $this->config('lws_authz.settings')->set('authorization_server', 'local')->save();
    $runtime = $requirements->runtime();
    $this->assertSame(RequirementSeverity::OK, $runtime['lws_authz_default_server']['severity']);
    $this->assertSame(RequirementSeverity::OK, $runtime['lws_authz_local_server']['severity']);
  }

  /**
   * Tests the settings form.
   */
  public function testSettingsForm(): void {
    $this->submit(['label' => 'Main', 'id' => 'main', 'issuer' => 'https://as.example', 'jwks' => '', 'default' => NULL]);
    $form = $this->container->get('class_resolver')->getInstanceFromDefinition(AuthorizationSettingsForm::class);
    $built = $this->container->get('form_builder')->getForm($form);
    $this->assertSame(['local', 'main'], array_keys($built['authorization_server']['#options']));
    $this->assertSame('local', $built['authorization_server']['#default_value']);
    $this->assertArrayHasKey('ssi_cid', $built['local']['suites']['#options']);

    $values = [
      'authorization_server' => 'main',
      'clock_skew' => 30,
      'suites' => [],
      'token_lifetime' => 120,
      'rate_limit_client' => 10,
      'rate_limit_subject' => 5,
      'rate_limit_access_requests' => 3,
      'op' => 'Save configuration',
    ];
    $formState = (new FormState())->setValues($values);
    $this->container->get('form_builder')->submitForm($form, $formState);
    $this->assertSame([], $formState->getErrors());
    $settings = $this->config('lws_authz.settings');
    $this->assertSame('main', $settings->get('authorization_server'));
    $this->assertSame(30, $settings->get('clock_skew'));
    $this->assertSame([], $settings->get('suites'));
    $this->assertSame(120, $settings->get('token_lifetime'));
    $this->assertSame(['client' => 10, 'subject' => 5, 'access_requests' => 3], $settings->get('rate_limits'));

    // The form refuses a lifetime above an hour.
    $formState = (new FormState())->setValues(['token_lifetime' => 7200] + $values);
    $this->container->get('form_builder')->submitForm($form, $formState);
    $this->assertArrayHasKey('token_lifetime', $formState->getErrors());
  }

}
