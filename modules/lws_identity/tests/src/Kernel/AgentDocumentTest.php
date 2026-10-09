<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_identity\Kernel;

use Ebremer\Lws\Auth\SigningKey;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests agent documents: what they say, who has one, and how they are served.
 */
#[Group('lws_identity')]
#[RunTestsInSeparateProcesses]
final class AgentDocumentTest extends IdentityKernelTestBase {

  /**
   * Tests the document of an agent with keys and OpenID Providers.
   */
  public function testDocument(): void {
    $alice = $this->agentUser('alice');
    $agent = self::BASE . '/lws/agents/' . $alice->uuid();
    $this->assertSame($agent, $this->uris->uriOf($alice));

    // No keys and no providers: the identifier alone.
    $response = $this->send('GET', '/lws/agents/' . $alice->uuid());
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('application/cid', $response->headers->get('Content-Type'));
    $this->assertSame('Accept', $response->headers->get('Vary'));
    $this->assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    $this->assertSame(['@context' => ['https://www.w3.org/ns/cid/v1'], 'id' => $agent], $this->json($response));

    $p256 = SigningKey::generateP256();
    $first = $this->keys->add($alice, $p256->publicKey->jwk() + ['kid' => 'laptop'], 'Laptop');
    $ed25519 = SigningKey::generateEd25519();
    $expires = time() + 86400;
    $second = $this->keys->add($alice, $ed25519->publicKey->jwk(), '', $expires);
    $providers = ['https://idp.example/realms/main', 'https://other.example'];
    $this->config('lws_identity.settings')->set('openid_providers', $providers)->save();

    $document = $this->json($this->send('GET', '/lws/agents/' . $alice->uuid()));
    $this->assertSame([
      [
        'id' => $agent . '#laptop',
        'type' => 'JsonWebKey',
        'controller' => $agent,
        'publicKeyJwk' => $p256->publicKey->jwk() + ['kid' => 'laptop'],
      ],
      [
        'id' => $agent . '#' . $second->getKeyId(),
        'type' => 'JsonWebKey',
        'controller' => $agent,
        'publicKeyJwk' => $ed25519->publicKey->jwk() + ['kid' => $second->getKeyId()],
        'expires' => gmdate('Y-m-d\TH:i:s\Z', $expires),
      ],
    ], $document['authentication']);
    $this->assertSame('laptop', $first->getKeyId());
    $this->assertSame([
      [
        'id' => $agent . '#openid-provider',
        'type' => 'https://www.w3.org/ns/lws#OpenIdProvider',
        'serviceEndpoint' => 'https://idp.example/realms/main',
      ],
      [
        'id' => $agent . '#openid-provider-2',
        'type' => 'https://www.w3.org/ns/lws#OpenIdProvider',
        'serviceEndpoint' => 'https://other.example',
      ],
    ], $document['service']);
    $this->assertArrayNotHasKey('name', $document);
  }

  /**
   * Tests content negotiation, entity tags and HEAD.
   */
  public function testServing(): void {
    $path = '/lws/agents/' . $this->agentUser('alice')->uuid();
    $this->assertSame('application/ld+json', $this->send('GET', $path, ['Accept' => 'application/ld+json'])->headers->get('Content-Type'));
    $this->assertSame('application/json', $this->send('GET', $path, ['Accept' => 'application/json'])->headers->get('Content-Type'));
    // Nothing else is served, and nothing is refused: the document it is.
    $this->assertSame('application/cid', $this->send('GET', $path, ['Accept' => 'text/turtle'])->headers->get('Content-Type'));

    $response = $this->send('GET', $path);
    $etag = (string) $response->headers->get('ETag');
    $this->assertNotSame('', $etag);
    $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
    $notModified = $this->send('GET', $path, ['If-None-Match' => $etag]);
    $this->assertSame(304, $notModified->getStatusCode());
    $this->assertSame('', $notModified->getContent());

    $head = $this->send('HEAD', $path);
    $this->assertSame(200, $head->getStatusCode());
    $this->assertSame('', $head->getContent());
    $this->assertSame($etag, $head->headers->get('ETag'));
  }

  /**
   * Tests who has no agent: no permission, blocked, unknown.
   */
  public function testNoAgent(): void {
    $bob = $this->createUser([], 'bob');
    $carol = $this->agentUser('carol');
    $carol->block()->save();
    foreach ([$bob->uuid(), $carol->uuid(), '0b5c3a5e-6a3e-4c1f-9d2e-3f1a2b3c4d5e'] as $uuid) {
      $this->assertProblem($this->send('GET', '/lws/agents/' . $uuid), 404, self::BASE . '/lws/agents/' . $uuid);
    }
    // Not a UUID: no route.
    $this->assertSame(404, $this->send('GET', '/lws/agents/carol')->getStatusCode());
  }

  /**
   * Tests that this site's authorization server reads its agents' documents.
   *
   * They are never fetched over HTTP, and never cached: a key removed is gone
   * at once.
   */
  public function testLocalResolution(): void {
    $this->container->set('http_client', new Client(['handler' => HandlerStack::create(new MockHandler([]))]));
    $alice = $this->agentUser('alice');
    $agent = $this->uris->uriOf($alice);
    $resolver = $this->container->get('lws_authz.cid_resolver');
    $documents = $this->container->get('lws_identity.agent_documents');
    $this->assertTrue($documents->serves($agent));
    $this->assertTrue($documents->serves(self::BASE . '/lws/agents/not-a-uuid'));
    $this->assertFalse($documents->serves('https://id.example/agent'));

    $key = $this->keys->add($alice, SigningKey::generateP256()->publicKey->jwk());
    $this->assertSame($agent . '#' . $key->getKeyId(), $resolver->resolve($agent . '#' . $key->getKeyId())['authentication'][0]['id']);
    $key->delete();
    $this->assertArrayNotHasKey('authentication', $resolver->resolve($agent));

    $alice->block()->save();
    try {
      $resolver->resolve($agent);
      $this->fail('A blocked user has no document.');
    }
    catch (\Exception $e) {
      $this->assertSame('This site has no controlled identifier document for the subject.', $e->getMessage());
    }
  }

}
