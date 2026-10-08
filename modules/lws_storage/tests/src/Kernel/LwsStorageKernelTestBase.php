<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_storage\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\ResourceRepository;
use Drupal\lws_storage\StorageManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base class for kernel tests of the storage module.
 */
abstract class LwsStorageKernelTestBase extends KernelTestBase {

  protected const BASE = 'https://storage.example';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'lws', 'lws_storage'];

  /**
   * The storage manager.
   */
  protected StorageManager $storages;

  /**
   * The resource repository.
   */
  protected ResourceRepository $resources;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('lws_storage');
    $this->installEntitySchema('lws_resource');
    $this->installEntitySchema('date_format');
    $this->installConfig(['system', 'lws']);
    $this->config('lws.settings')->set('base_url', self::BASE)->save();
    $this->storages = $this->container->get('lws_storage.storage_manager');
    $this->resources = $this->container->get('lws_storage.resource_repository');
  }

  /**
   * Loads a storage by slug.
   */
  protected function loadStorage(string $slug): LwsStorageInterface {
    $storages = $this->container->get('entity_type.manager')->getStorage('lws_storage')->loadByProperties(['slug' => $slug]);
    $storage = reset($storages);
    $this->assertInstanceOf(LwsStorageInterface::class, $storage);
    return $storage;
  }

  /**
   * Sends a request through the HTTP kernel.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $path
   *   The raw path, with any query string.
   * @param array<string, string> $headers
   *   Request headers.
   */
  protected function send(string $method, string $path, array $headers = []): Response {
    $request = Request::create(self::BASE . $path, $method);
    foreach ($headers as $name => $value) {
      $request->headers->set($name, $value);
    }
    return $this->container->get('http_kernel')->handle($request);
  }

  /**
   * Decodes a JSON response body.
   *
   * @return array<string, mixed>
   *   The document.
   */
  protected function json(Response $response): array {
    $body = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertIsArray($body);
    return $body;
  }

  /**
   * Asserts that a response is a problem details document.
   */
  protected function assertProblem(Response $response, int $status, ?string $instance = NULL): void {
    $this->assertSame($status, $response->getStatusCode());
    $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));
    $problem = $this->json($response);
    $this->assertSame($status, $problem['status']);
    $this->assertSame($instance, $problem['instance'] ?? NULL);
  }

}
