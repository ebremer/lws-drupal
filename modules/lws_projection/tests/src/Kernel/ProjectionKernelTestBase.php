<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_projection\Kernel;

use Drupal\Tests\lws_storage\Kernel\LwsStorageKernelTestBase;
use Drupal\lws_projection\Entity\Projection;
use Drupal\lws_projection\Entity\ProjectionInterface;
use Drupal\lws_projection\Projector;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\user\RoleInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base class for kernel tests of projections.
 *
 * There are two content types, article and page, and visitors may access
 * content.
 */
abstract class ProjectionKernelTestBase extends LwsStorageKernelTestBase {

  /**
   * The projected storage.
   */
  protected const STORAGE = '/lws/content/';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'field',
    'filter',
    'text',
    'node',
    'serialization',
    'lws',
    'lws_authz',
    'lws_storage',
    'lws_projection',
  ];

  /**
   * The projector.
   */
  protected Projector $projector;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['user', 'node']);
    $this->container->get('node.grant_storage')->writeDefault();
    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, ['access content']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $this->projector = $this->container->get('lws_projection.projector');
  }

  /**
   * Makes a node.
   */
  protected function node(string $type, string $title, bool $published = TRUE): NodeInterface {
    $node = Node::create(['type' => $type, 'title' => $title, 'status' => $published, 'uid' => 0]);
    $node->save();
    return $node;
  }

  /**
   * Makes the projection "content", of articles unless told otherwise.
   *
   * @param list<array{entity_type: string, bundle: string, type: string}>|null $bundles
   *   What it projects.
   * @param bool $public
   *   Whether anyone may read it.
   */
  protected function projection(?array $bundles = NULL, bool $public = TRUE): ProjectionInterface {
    $projection = Projection::create([
      'id' => 'content',
      'label' => 'Content',
      'slug' => 'content',
      'public' => $public,
      'bundles' => $bundles ?? [
        ['entity_type' => 'node', 'bundle' => 'article', 'type' => 'https://schema.org/Article'],
      ],
    ]);
    $projection->save();
    return $projection;
  }

  /**
   * Runs the projection sync queue, as cron does.
   */
  protected function runQueue(): void {
    $queue = $this->container->get('queue')->get(Projector::QUEUE);
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance(Projector::QUEUE);
    while (is_object($item = $queue->claimItem())) {
      $worker->processItem(get_object_vars($item)['data'] ?? NULL);
      $queue->deleteItem($item);
    }
  }

  /**
   * The paths of a container's members.
   *
   * @return list<string>
   *   The member URIs, without the storage URI.
   */
  protected function members(string $container): array {
    $response = $this->send('GET', self::STORAGE . $container, ['Accept' => 'application/lws+json']);
    $this->assertSame(200, $response->getStatusCode(), $container);
    $items = $this->json($response)['items'] ?? [];
    $this->assertIsArray($items);
    $paths = array_map(static fn (array $item): string => substr((string) $item['id'], strlen(self::BASE . self::STORAGE)), $items);
    sort($paths);
    return $paths;
  }

  /**
   * The JSON a data resource serves.
   *
   * @return array<string, mixed>
   *   The decoded JSON.
   */
  protected function content(Response $response): array {
    $content = json_decode($this->body($response), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertIsArray($content);
    return $content;
  }

  /**
   * Reads a node's resource.
   */
  protected function read(NodeInterface $node, string $bundle = 'article'): Response {
    return $this->send('GET', self::STORAGE . 'root/node/' . $bundle . '/' . $node->id());
  }

}
