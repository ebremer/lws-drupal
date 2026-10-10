<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_projection\Kernel;

use Drupal\lws_authz\Policy\AccessPolicy;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests projecting content, serving it, and that it is read-only.
 */
#[Group('lws_projection')]
#[RunTestsInSeparateProcesses]
final class ProjectionTest extends ProjectionKernelTestBase {

  /**
   * Tests what a sync projects, and how it is served.
   */
  public function testProject(): void {
    $hello = $this->node('article', 'Hello');
    $draft = $this->node('article', 'Draft', FALSE);
    $about = $this->node('page', 'About');
    $this->projection();
    $this->assertNotNull($this->container->get('lws_storage.storage_registry')->get('content'), 'The storage is made with the projection.');
    $this->assertSame([], $this->members('root/'), 'Its content comes with the sync.');
    $this->runQueue();

    $this->assertSame(['root/node/'], $this->members('root/'));
    $this->assertSame(['root/node/article/'], $this->members('root/node/'));
    $this->assertSame(['root/node/article/' . $hello->id()], $this->members('root/node/article/'));

    $response = $this->read($hello);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('application/json', $response->headers->get('Content-Type'));
    $json = $this->content($response);
    $this->assertSame('Hello', $json['title'][0]['value'] ?? NULL);
    $this->assertSame($hello->uuid(), $json['uuid'][0]['value'] ?? NULL);
    // Only what a visitor may see.
    $this->assertArrayNotHasKey('revision_log', $json);
    $links = implode(', ', $response->headers->all('link'));
    $this->assertStringContainsString('<https://schema.org/Article>; rel="type"', $links);
    // Its page, in its linkset.
    $this->assertSame(1, preg_match('#<' . preg_quote(self::BASE, '#') . '(/lws/content/meta/[^>]+)>; rel="linkset"#', $links, $linkset));
    $set = $this->json($this->send('GET', $linkset[1], ['Accept' => 'application/linkset+json']))['linkset'][0] ?? [];
    $this->assertSame([['href' => self::BASE . '/node/' . $hello->id(), 'type' => 'text/html']], $set['alternate'] ?? NULL);

    $this->assertSame(404, $this->read($draft)->getStatusCode());
    $this->assertSame(404, $this->read($about, 'page')->getStatusCode());
    $this->assertSame(404, $this->send('GET', self::STORAGE . 'root/node/page/')->getStatusCode());

    // A sync again writes nothing that did not change.
    $etag = $response->headers->get('ETag');
    $projection = $this->container->get('lws_projection.projections')->load('content');
    $this->assertNotNull($projection);
    $this->projector->sync($projection);
    $this->assertSame($etag, $this->read($hello)->headers->get('ETag'));
  }

  /**
   * Tests that nobody may write to a projected storage.
   */
  public function testReadOnly(): void {
    $hello = $this->node('article', 'Hello');
    $this->projection(public: FALSE);
    $this->runQueue();
    $this->assertSame(401, $this->read($hello)->getStatusCode(), 'Not public: a token is needed.');

    $storage = $this->container->get('lws_storage.storage_registry')->get('content');
    $this->assertNotNull($storage);
    $bob = 'https://bob.example/#me';
    $document = AccessPolicy::document($bob, ['read', 'create', 'modify', 'delete'], 'StorageResource', [$storage->uri]);
    $this->container->get('lws_authz.policy_store')->add($storage, $this->container->get('lws_authz.policy_parser')->parse($document, $storage));
    $token = ['Authorization' => 'Bearer ' . $this->token($bob, ['aud' => $storage->uri])];
    $path = self::STORAGE . 'root/node/article/' . $hello->id();

    $this->assertSame(200, $this->send('GET', $path, $token)->getStatusCode());
    $this->assertSame(403, $this->send('PUT', $path, $token + ['Content-Type' => 'application/json'], '{}')->getStatusCode());
    $this->assertSame(403, $this->send('PATCH', $path, $token + ['Content-Type' => 'application/json-patch+json'], '[]')->getStatusCode());
    $this->assertSame(403, $this->send('DELETE', $path, $token)->getStatusCode());
    $this->assertSame(403, $this->send('POST', self::STORAGE . 'root/', $token + ['Content-Type' => 'text/plain'], 'x')->getStatusCode());
  }

  /**
   * Tests that a projection never takes over a storage it did not make.
   */
  public function testSlugTaken(): void {
    $this->storages->createStorage('content', 'Someone else\'s', ['https://alice.example/#me']);
    $token = 'Bearer ' . $this->token('https://alice.example/#me', ['aud' => self::BASE . self::STORAGE]);
    $headers = ['Slug' => 'mine', 'Content-Type' => 'text/plain', 'Authorization' => $token];
    $this->assertSame(201, $this->send('POST', self::STORAGE . 'root/', $headers, 'Mine')->getStatusCode());

    $this->node('article', 'Hello');
    $projection = $this->projection();
    $this->runQueue();
    $this->projector->sync($projection);
    $this->assertNull($this->container->get('lws_projection.projections')->storageId('content'));
    $storage = $this->container->get('lws_storage.storage_registry')->get('content');
    $this->assertNotNull($storage);
    $this->assertFalse($this->container->get('lws_projection.projections')->isProjected($storage->id));
    $this->assertNotNull($this->resources->findByPath($this->loadStorage('content'), 'root/mine'), 'Its content stays.');
    $this->assertNull($this->resources->findByPath($this->loadStorage('content'), 'root/node/'));
  }

}
