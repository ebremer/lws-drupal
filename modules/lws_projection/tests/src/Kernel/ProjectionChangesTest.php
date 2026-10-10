<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_projection\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\lws_projection\Entity\Projection;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that projections follow content, access and their settings.
 */
#[Group('lws_projection')]
#[RunTestsInSeparateProcesses]
final class ProjectionChangesTest extends ProjectionKernelTestBase {

  /**
   * Tests changes to content, projected when the request ends.
   */
  public function testContentChanges(): void {
    $hello = $this->node('article', 'Hello');
    $this->projection();
    $this->runQueue();
    $etag = $this->read($hello)->headers->get('ETag');

    $hello->setTitle('Hello again')->save();
    $this->assertSame($etag, $this->read($hello)->headers->get('ETag'), 'Not before the request ends.');
    $this->projector->flush();
    $response = $this->read($hello);
    $this->assertSame('Hello again', $this->content($response)['title'][0]['value'] ?? NULL);
    $this->assertNotSame($etag, $response->headers->get('ETag'));

    $etag = $response->headers->get('ETag');
    $hello->save();
    $this->projector->flush();
    $this->assertSame($etag, $this->read($hello)->headers->get('ETag'), 'A save that changes nothing writes nothing.');

    $hello->setUnpublished()->save();
    $this->projector->flush();
    $this->assertSame(404, $this->read($hello)->getStatusCode());
    $hello->setPublished()->save();
    $this->projector->flush();
    $this->assertSame(200, $this->read($hello)->getStatusCode());

    $news = $this->node('article', 'News');
    $page = $this->node('page', 'Page');
    $this->projector->flush();
    $this->assertSame(200, $this->read($news)->getStatusCode());
    $this->assertSame(404, $this->read($page, 'page')->getStatusCode());

    $hello->delete();
    $this->projector->flush();
    $this->assertSame(404, $this->read($hello)->getStatusCode());
    $this->assertSame(['root/node/article/' . $news->id()], $this->members('root/node/article/'));
  }

  /**
   * Tests a change to what visitors may see.
   */
  public function testAccessChange(): void {
    $hello = $this->node('article', 'Hello');
    $this->projection();
    $this->runQueue();
    user_role_revoke_permissions(RoleInterface::ANONYMOUS_ID, ['access content']);
    $this->assertSame(200, $this->read($hello)->getStatusCode(), 'Until the sync runs.');
    $this->runQueue();
    $this->assertSame(404, $this->read($hello)->getStatusCode());
    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, ['access content']);
    $this->runQueue();
    $this->assertSame(200, $this->read($hello)->getStatusCode());
  }

  /**
   * Tests changes to the projection, and taking it away.
   */
  public function testProjectionChanges(): void {
    $hello = $this->node('article', 'Hello');
    $about = $this->node('page', 'About');
    $projection = $this->projection();
    $this->runQueue();

    $projection->setBundles([
      ['entity_type' => 'node', 'bundle' => 'article', 'type' => ''],
      ['entity_type' => 'node', 'bundle' => 'page', 'type' => 'https://schema.org/WebPage'],
    ])->save();
    $this->runQueue();
    $this->assertSame(['root/node/article/', 'root/node/page/'], $this->members('root/node/'));
    $this->assertStringNotContainsString('schema.org/Article', implode(', ', $this->read($hello)->headers->all('link')));
    $this->assertStringContainsString('<https://schema.org/WebPage>; rel="type"', implode(', ', $this->read($about, 'page')->headers->all('link')));

    $projection->setBundles([['entity_type' => 'node', 'bundle' => 'page', 'type' => '']])->save();
    $this->runQueue();
    $this->assertSame(['root/node/page/'], $this->members('root/node/'));
    $this->assertSame(404, $this->read($hello)->getStatusCode());

    // Not public any more: only its policies say who may read.
    $projection->set('public', FALSE)->save();
    $this->assertSame(401, $this->read($about, 'page')->getStatusCode());

    // Deleting the content type takes it out of the projection.
    $this->container->get('entity_type.manager')->getStorage('node')->delete([$about]);
    $this->container->get('entity_type.manager')->getStorage('node_type')->load('page')?->delete();
    $reloaded = Projection::load('content');
    $this->assertNotNull($reloaded);
    $this->assertSame([], $reloaded->getBundles());

    // Taken away, the projection leaves an ordinary storage.
    $storage = $this->container->get('lws_storage.storage_registry')->get('content');
    $this->assertNotNull($storage);
    $reloaded->delete();
    $this->assertFalse($this->container->get('lws_projection.projections')->isProjected($storage->id));
    $this->assertNotNull($this->container->get('lws_storage.storage_registry')->get('content'));
  }

  /**
   * Tests the form.
   */
  public function testForm(): void {
    $this->storages->createStorage('taken', 'Taken');
    $submit = function (array $values): array {
      $form = $this->container->get('entity_type.manager')->getFormObject('lws_projection', 'add')->setEntity(Projection::create());
      $state = (new FormState())->setValues($values + [
        'label' => 'Articles',
        'id' => 'articles',
        'slug' => 'articles',
        'public' => 1,
        'bundles' => [],
        'op' => 'Save',
      ]);
      $this->container->get('form_builder')->submitForm($form, $state);
      return array_map('strval', $state->getErrors());
    };
    $form = $this->container->get('entity_type.manager')->getFormObject('lws_projection', 'add')->setEntity(Projection::create());
    $built = $this->container->get('form_builder')->getForm($form);
    $this->assertArrayHasKey('node:article', $built['bundles']);
    $this->assertArrayHasKey('user:user', $built['bundles']);
    $this->assertArrayNotHasKey('lws_resource:lws_resource', $built['bundles'], 'Not LWS content itself.');

    $articles = static fn (string $type = ''): array => ['node:article' => ['selected' => 1, 'type' => $type]];
    $this->assertArrayHasKey('bundles', $submit([]));
    $this->assertArrayHasKey('slug', $submit(['slug' => 'taken', 'bundles' => $articles()]));
    $this->assertArrayHasKey('slug', $submit(['slug' => 'oauth', 'bundles' => $articles()]));
    $this->assertArrayHasKey('bundles][node:article][type', $submit(['bundles' => $articles('not a uri')]));
    $bundles = $articles('https://schema.org/Article') + ['node:page' => ['selected' => 0, 'type' => 'ignored']];
    $this->assertSame([], $submit(['bundles' => $bundles]));

    $saved = Projection::load('articles');
    $this->assertNotNull($saved);
    $this->assertSame('articles', $saved->getSlug());
    $this->assertTrue($saved->isPublic());
    $this->assertSame([['entity_type' => 'node', 'bundle' => 'article', 'type' => 'https://schema.org/Article']], $saved->getBundles());
    $this->assertContains('node.type.article', $saved->getDependencies()['config'] ?? []);
    $this->assertNotNull($this->container->get('lws_storage.storage_registry')->get('articles'));
  }

}
