<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_index\Kernel;

use Drupal\lws_authz\Policy\AccessPolicy;
use Drupal\lws_index\Indexer;
use Drupal\Tests\lws_storage\Kernel\LwsStorageKernelTestBase;
use Ebremer\Lws\MediaType;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base class for kernel tests of the type index.
 *
 * Alice controls the storage "alice"; types are under T.
 */
abstract class IndexKernelTestBase extends LwsStorageKernelTestBase {

  protected const ALICE = 'https://alice.example/profile#me';

  protected const BOB = 'https://bob.example/profile#me';

  protected const STORAGE = self::BASE . '/lws/alice/';

  protected const SEARCH = '/lws/alice/types/search';

  protected const INDEX = '/lws/alice/types/index';

  /**
   * The namespace of the types tests give resources.
   */
  protected const T = 'https://types.example/#';

  protected const CONTAINER = '<https://www.w3.org/ns/lws#Container>; rel="type"';

  protected const LWS_CONTAINER = 'https://www.w3.org/ns/lws#Container';

  protected const LWS_DATA = 'https://www.w3.org/ns/lws#DataResource';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['lws_index'];

  /**
   * The page size of the storage; NULL for the default.
   */
  protected ?int $pageSize = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('lws_index', [Indexer::TABLE]);
    $this->installConfig(['lws_index']);
    $this->storages->createStorage('alice', 'Alice', [self::ALICE], NULL, NULL, NULL, $this->pageSize);
  }

  /**
   * Sends a request as an agent.
   *
   * @param string|null $agent
   *   The agent; NULL for none.
   * @param string $method
   *   The method.
   * @param string $path
   *   The raw path.
   * @param array<string, string|list<string>> $headers
   *   Request headers.
   * @param string|null $body
   *   The request body.
   */
  protected function as(?string $agent, string $method, string $path, array $headers = [], ?string $body = NULL): Response {
    $this->agent = $agent;
    try {
      return $this->send($method, $path, $headers, $body);
    }
    finally {
      $this->agent = NULL;
    }
  }

  /**
   * Searches, as an agent.
   *
   * @param string|null $agent
   *   The agent; NULL for none.
   * @param array<string, mixed>|string $filter
   *   The filter, or the body to send.
   * @param array<string, string|list<string>> $headers
   *   Headers to add or replace.
   */
  protected function search(?string $agent, array|string $filter, array $headers = []): Response {
    $body = is_string($filter) ? $filter : json_encode($filter === [] ? new \stdClass() : $filter, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return $this->as($agent, 'QUERY', self::SEARCH, $headers + ['Content-Type' => MediaType::LWS_QUERY_JSON], $body);
  }

  /**
   * The paths of the resources the first page of a search holds.
   *
   * @param string|null $agent
   *   The agent; NULL for none.
   * @param array<string, mixed> $filter
   *   The filter.
   *
   * @return list<string>
   *   Paths under the storage URI.
   */
  protected function find(?string $agent, array $filter): array {
    return $this->found($this->search($agent, $filter));
  }

  /**
   * The paths of the resources a page of results holds, in order.
   *
   * @return list<string>
   *   Paths under the storage URI, such as "root/a.txt".
   */
  protected function found(Response $response): array {
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $page = $this->json($response);
    $this->assertSame('ContainerPage', $page['type']);
    $this->assertIsArray($page['items']);
    return array_values(array_map(static fn (array $item): string => substr($item['id'], strlen(self::STORAGE)), $page['items']));
  }

  /**
   * The types a page of the type index lists, in order.
   *
   * @return list<string>
   *   The types.
   */
  protected function listed(Response $response): array {
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $page = $this->json($response);
    $this->assertSame('TypeIndex', $page['type']);
    $this->assertIsArray($page['items']);
    return array_values(array_map(static fn (array $item): string => $item['id'], $page['items']));
  }

  /**
   * The target of a link a response has, if it has one.
   */
  protected function link(Response $response, string $rel): ?string {
    foreach ($response->headers->all('link') as $link) {
      if (preg_match('/^<([^>]*)>;\s*rel="' . preg_quote($rel, '/') . '"/', (string) $link, $match) === 1) {
        return $match[1];
      }
    }
    return NULL;
  }

  /**
   * Creates a data resource as Alice.
   *
   * @param string $parent
   *   The container, as a path under the storage URI, such as "root/".
   * @param string $name
   *   The name.
   * @param list<string> $types
   *   Types, under T.
   * @param array<string, string> $links
   *   Further links, as relation => target.
   * @param string $mediaType
   *   The media type.
   *
   * @return string
   *   The resource's path under the storage URI.
   */
  protected function create(string $parent, string $name, array $types = [], array $links = [], string $mediaType = 'text/plain'): string {
    $headers = ['Content-Type' => $mediaType, 'Slug' => $name, 'Link' => self::links($types, $links)];
    $response = $this->as(self::ALICE, 'POST', '/lws/alice/' . $parent, $headers, 'content');
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    return substr((string) $response->headers->get('Location'), strlen(self::STORAGE));
  }

  /**
   * Creates a container as Alice.
   *
   * @param string $parent
   *   The parent container, as a path under the storage URI.
   * @param string $name
   *   The name.
   * @param list<string> $types
   *   Types, under T.
   *
   * @return string
   *   The container's path under the storage URI.
   */
  protected function createContainer(string $parent, string $name, array $types = []): string {
    $headers = ['Slug' => $name, 'Link' => [self::CONTAINER, ...self::links($types, [])]];
    $response = $this->as(self::ALICE, 'POST', '/lws/alice/' . $parent, $headers);
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    return substr((string) $response->headers->get('Location'), strlen(self::STORAGE));
  }

  /**
   * Link header values for types and further links.
   *
   * @param list<string> $types
   *   Types, under T.
   * @param array<string, string> $links
   *   Relation => target.
   *
   * @return list<string>
   *   The values.
   */
  protected static function links(array $types, array $links): array {
    $values = array_map(static fn (string $type): string => '<' . self::T . $type . '>; rel="type"', $types);
    foreach ($links as $rel => $href) {
      $values[] = '<' . $href . '>; rel="' . $rel . '"';
    }
    return $values;
  }

  /**
   * Lets an agent read resources of the storage.
   *
   * @param string $agent
   *   The agent, or AccessPolicy::PUBLIC.
   * @param list<string> $paths
   *   Paths under the storage URI; "" for the storage itself.
   * @param list<array<string, mixed>> $constraints
   *   Constraint objects.
   *
   * @return int
   *   The policy ID.
   */
  protected function letRead(string $agent, array $paths, array $constraints = []): int {
    $storage = $this->container->get('lws_storage.storage_registry')->get('alice');
    $this->assertNotNull($storage);
    $document = AccessPolicy::document($agent, ['read'], 'StorageResource', array_map(static fn (string $path): string => self::STORAGE . $path, $paths));
    $document['constraint'] = $constraints;
    $policy = $this->container->get('lws_authz.policy_parser')->parse($document, $storage);
    return (int) $this->container->get('lws_authz.policy_store')->add($storage, $policy)->id();
  }

  /**
   * Removes a policy.
   */
  protected function revoke(int $policy): void {
    $storage = $this->container->get('lws_storage.storage_registry')->get('alice');
    $this->assertNotNull($storage);
    $this->container->get('lws_authz.policy_store')->load($storage->id, $policy)?->delete();
  }

}
