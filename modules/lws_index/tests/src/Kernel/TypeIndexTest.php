<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_index\Kernel;

use Drupal\lws_authz\Policy\AccessPolicy;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the type index service.
 */
#[Group('lws')]
#[RunTestsInSeparateProcesses]
final class TypeIndexTest extends IndexKernelTestBase {

  /**
   * Tests the type index of an agent who may read everything.
   */
  public function testTypeIndex(): void {
    $this->create('root/', 'one', ['Person', 'Agent']);
    $this->create('root/', 'two', ['Person']);
    $this->createContainer('root/', 'events', ['Event']);

    $response = $this->as(self::ALICE, 'GET', self::INDEX);
    $types = [self::T . 'Agent', self::T . 'Event', self::T . 'Person', self::LWS_CONTAINER, self::LWS_DATA];
    $this->assertSame($types, $this->listed($response));
    $page = $this->json($response);
    $this->assertSame('https://www.w3.org/ns/lws/v1', $page['@context']);
    $this->assertSame(5, $page['totalItems']);
    $this->assertSame('application/lws+json', $response->headers->get('Content-Type'));
    $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    $this->assertSame(self::STORAGE . 'types/index', $this->link($response, 'first'));
    $this->assertNull($this->link($response, 'next'));
    $this->assertSame(406, $this->as(self::ALICE, 'GET', self::INDEX, ['Accept' => 'text/html'])->getStatusCode());
    $head = $this->as(self::ALICE, 'HEAD', self::INDEX);
    $this->assertSame(200, $head->getStatusCode());
    $this->assertSame('', $head->getContent());
    $this->assertSame(404, $this->as(self::ALICE, 'GET', self::INDEX . '?page=nonsense')->getStatusCode());

    // A type nothing bears any more is listed no more.
    $this->assertSame(204, $this->as(self::ALICE, 'DELETE', '/lws/alice/root/one')->getStatusCode());
    $this->assertSame([self::T . 'Event', self::T . 'Person', self::LWS_CONTAINER, self::LWS_DATA], $this->listed($this->as(self::ALICE, 'GET', self::INDEX)));
  }

  /**
   * Tests that the type index lists only the types of what the agent reads.
   */
  public function testAuthorization(): void {
    $shared = $this->createContainer('root/', 'shared');
    $this->create($shared, 'visible', ['Alpha']);
    $private = $this->createContainer('root/', 'private', ['Secret']);
    $this->create($private, 'hidden', ['Beta']);
    $this->create($private, 'both', ['Alpha']);

    $this->assertSame([], $this->listed($this->as(self::BOB, 'GET', self::INDEX)));
    $this->assertSame(0, $this->json($this->as(NULL, 'GET', self::INDEX))['totalItems']);

    $policy = $this->letRead(self::BOB, [$shared]);
    $response = $this->as(self::BOB, 'GET', self::INDEX);
    $this->assertSame([self::T . 'Alpha', self::LWS_CONTAINER, self::LWS_DATA], $this->listed($response));
    $this->assertSame(3, $this->json($response)['totalItems']);

    // Revoking takes effect at once.
    $this->revoke($policy);
    $this->assertSame([], $this->listed($this->as(self::BOB, 'GET', self::INDEX)));

    // A policy on the whole storage, for one format: each resource is
    // checked. Containers have no format.
    $this->create($private, 'picture', ['Picture'], [], 'image/png');
    $this->letRead(self::BOB, [''], [['leftOperand' => 'format', 'operator' => 'eq', 'rightOperand' => 'image/png']]);
    $this->assertSame([self::T . 'Picture', self::LWS_DATA], $this->listed($this->as(self::BOB, 'GET', self::INDEX)));

    $this->letRead(AccessPolicy::PUBLIC, ['root/private/hidden']);
    $this->assertSame([self::T . 'Beta', self::LWS_DATA], $this->listed($this->as(NULL, 'GET', self::INDEX)));
  }

  /**
   * Tests that a rejected token is challenged, as anywhere in the storage.
   */
  public function testChallenge(): void {
    $headers = ['Authorization' => 'Bearer not-a-token', 'Content-Type' => 'application/lws-query+json'];
    foreach (['GET' => self::INDEX, 'QUERY' => self::SEARCH] as $method => $path) {
      $response = $this->as(NULL, $method, $path, $headers, '{}');
      $this->assertSame(401, $response->getStatusCode(), $method);
      $challenge = (string) $response->headers->get('WWW-Authenticate');
      $this->assertStringStartsWith('Bearer resource_metadata="' . self::BASE . '/.well-known/oauth-protected-resource' . $path . '"', $challenge);
      $this->assertStringContainsString('error="invalid_token"', $challenge);
    }
  }

  /**
   * Tests that the answers never claim the LWS profile.
   *
   * Neither the type index nor a page of search results is a container, so
   * neither is in the profile, and JSON-LD asked for in it is not offered.
   */
  public function testNoProfile(): void {
    $this->create('root/', 'one', ['Person']);
    $profile = 'application/ld+json; profile="https://www.w3.org/ns/lws/v1"';
    $this->assertSame(406, $this->as(self::ALICE, 'GET', self::INDEX, ['Accept' => $profile])->getStatusCode());
    $response = $this->as(self::ALICE, 'GET', self::INDEX, ['Accept' => $profile . ', application/ld+json;q=0.5']);
    $this->assertSame('application/ld+json', $response->headers->get('Content-Type'));
    $this->assertSame(406, $this->search(self::ALICE, ['type' => [self::T . 'Person']], ['Accept' => $profile])->getStatusCode());
    $response = $this->search(self::ALICE, ['type' => [self::T . 'Person']], ['Accept' => $profile . ', application/lws+json;q=0.5']);
    $this->assertSame('application/lws+json', $response->headers->get('Content-Type'));
  }

}
