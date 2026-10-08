<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_notify\Kernel;

use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\lws\Outbound\HostResolverInterface;
use Drupal\Tests\lws_storage\Kernel\LwsStorageKernelTestBase;
use Ebremer\Lws\Model\StorageDescription;
use Ebremer\Lws\Notification\VerifiedNotification;
use Ebremer\Lws\Notification\WebhookVerifier;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base class for kernel tests of notifications.
 *
 * Alice controls the storage "alice". Inboxes are a mock server that
 * records each delivery and answers 202, or what a test scripts; every host
 * resolves to a public address but internal.example, which is private.
 */
abstract class NotifyKernelTestBase extends LwsStorageKernelTestBase {

  protected const ALICE = 'https://alice.example/profile#me';

  protected const BOB = 'https://bob.example/profile#me';

  protected const INBOX = 'https://inbox.example/hooks/alice';

  protected const STORAGE = self::BASE . '/lws/alice/';

  protected const CONTAINER = '<https://www.w3.org/ns/lws#Container>; rel="type"';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'lws', 'lws_authz', 'lws_storage', 'lws_notify'];

  /**
   * The deliveries made, oldest first.
   *
   * @var list<array{url: string, headers: array<string, string>, body: string, status: int}>
   */
  protected array $deliveries = [];

  /**
   * The statuses each inbox answers with, in turn; then 202.
   *
   * 0 stands for no answer at all.
   *
   * @var array<string, list<int>>
   */
  protected array $answers = [];

  /**
   * What the lws_notify channel logged, as "level: message".
   *
   * @var list<string>
   */
  protected array $logged = [];

  /**
   * Whether the test expects lws_notify to log errors.
   *
   * Otherwise any error fails it: the notifier logs what it cannot do
   * rather than fail the request that made a change.
   */
  protected bool $errorsExpected = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('lws_subscription');
    $this->installConfig(['lws_notify']);
    $this->setSetting('lws_notify_key_directory', $this->siteDirectory . '/notify-keys');
    // A short first retry, so that tests wait a second rather than two.
    $this->config('lws_notify.settings')->set('delivery.retry_delays', [1, 60, 600])->save();

    $this->installMocks();
    $this->storages->createStorage('alice', 'Alice', [self::ALICE]);
  }

  /**
   * Puts the mock inboxes, resolver and log in the container.
   *
   * Again after anything that rebuilds the container.
   */
  protected function installMocks(): void {
    $handler = function (RequestInterface $request): PromiseInterface {
      $url = (string) $request->getUri();
      $answers = $this->answers[$url] ?? [];
      $status = array_shift($answers) ?? 202;
      $this->answers[$url] = $answers;
      $headers = [];
      foreach ($request->getHeaders() as $name => $values) {
        $headers[strtolower($name)] = implode(', ', $values);
      }
      $this->deliveries[] = [
        'url' => $url,
        'headers' => $headers,
        'body' => (string) $request->getBody(),
        'status' => $status,
      ];
      if ($status === 0) {
        return new RejectedPromise(new ConnectException('Connection refused', $request));
      }
      return new FulfilledPromise(new GuzzleResponse($status));
    };
    $this->container->set('http_client', new Client(['handler' => HandlerStack::create($handler)]));
    $this->container->set('lws.host_resolver', new class implements HostResolverInterface {

      /**
       * {@inheritdoc}
       */
      public function resolve(string $host): array {
        return $host === 'internal.example' ? ['10.0.0.5'] : ['93.184.215.14'];
      }

    });
    $this->container->get('logger.factory')->addLogger(new class(function (string $line): void {
      $this->logged[] = $line;
    }) implements LoggerInterface {

      use RfcLoggerTrait;

      /**
       * @param \Closure(string): void $record
       *   Records a line.
       */
      public function __construct(private readonly \Closure $record) {}

      /**
       * {@inheritdoc}
       *
       * @param mixed $level
       *   The level.
       * @param string|\Stringable $message
       *   The message.
       * @param array<string, mixed> $context
       *   The context.
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        if (($context['channel'] ?? NULL) === 'lws_notify') {
          $placeholders = array_filter($context, static fn (string $key): bool => str_starts_with($key, '@'), ARRAY_FILTER_USE_KEY);
          ($this->record)($level . ': ' . strtr((string) $message, array_map('strval', $placeholders)));
        }
      }

    });
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (!$this->errorsExpected) {
      $errors = array_filter($this->logged, static fn (string $line): bool => (int) $line <= RfcLogLevel::ERROR);
      $this->assertSame([], array_values($errors), 'lws_notify logged no errors.');
    }
    parent::tearDown();
  }

  /**
   * {@inheritdoc}
   *
   * And delivers what the request made, as the end of a request would.
   */
  protected function send(string $method, string $path, array $headers = [], ?string $body = NULL): Response {
    $response = parent::send($method, $path, $headers, $body);
    $this->container->get('lws_notify.notifier')->flush();
    return $response;
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
   * Subscribes an agent.
   *
   * @param string $agent
   *   The agent.
   * @param list<string> $topics
   *   The topics, as paths under the storage URI, such as "root/".
   * @param array<string, mixed> $request
   *   Members to add to the request, or to replace.
   */
  protected function subscribe(string $agent, array $topics, array $request = []): Response {
    $request += [
      '@context' => ['https://www.w3.org/ns/lws/v1'],
      'type' => 'WebhookSubscription',
      'topic' => array_map(static fn (string $path): string => self::STORAGE . $path, $topics),
      'inbox' => self::INBOX,
    ];
    return $this->as($agent, 'POST', '/lws/alice/notifications/', ['Content-Type' => 'application/lws+json'], json_encode($request, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
  }

  /**
   * Creates a container as Alice.
   *
   * @return string
   *   Its URI.
   */
  protected function createContainer(string $parent, string $name): string {
    $response = $this->as(self::ALICE, 'POST', '/lws/alice/' . $parent, ['Link' => self::CONTAINER, 'Slug' => $name]);
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    return (string) $response->headers->get('Location');
  }

  /**
   * Creates a data resource as Alice.
   *
   * @return string
   *   Its URI.
   */
  protected function createData(string $parent, string $name, string $content = 'content', string $type = 'text/plain'): string {
    $response = $this->as(self::ALICE, 'POST', '/lws/alice/' . $parent, ['Content-Type' => $type, 'Slug' => $name], $content);
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    return (string) $response->headers->get('Location');
  }

  /**
   * Gives an agent read access to resources of the storage.
   *
   * @param string $agent
   *   The agent.
   * @param list<string> $paths
   *   The resources, as paths under the storage URI.
   * @param string $targetType
   *   The target type: StorageResource, Container or DataResource.
   *
   * @return int
   *   The policy ID.
   */
  protected function letRead(string $agent, array $paths, string $targetType = 'StorageResource'): int {
    $storage = $this->container->get('lws_storage.storage_registry')->get('alice');
    $this->assertNotNull($storage);
    $document = [
      'type' => ['AccessPolicy'],
      'action' => ['read'],
      'assignee' => $agent,
      'target' => [
        'type' => $targetType,
        'value' => array_map(static fn (string $path): string => self::STORAGE . $path, $paths),
      ],
    ];
    $policy = $this->container->get('lws_authz.policy_parser')->parse($document, $storage);
    return (int) $this->container->get('lws_authz.policy_store')->add($storage, $policy)->id();
  }

  /**
   * The deliveries made, oldest first.
   *
   * @return list<array{url: string, headers: array<string, string>, body: string, status: int}>
   *   The deliveries.
   *
   * @phpstan-impure
   */
  protected function deliveries(): array {
    return $this->deliveries;
  }

  /**
   * The deliveries to an inbox.
   *
   * @return list<array{url: string, headers: array<string, string>, body: string, status: int}>
   *   The deliveries, oldest first.
   */
  protected function deliveriesTo(string $inbox = self::INBOX): array {
    return array_values(array_filter($this->deliveries(), static fn (array $delivery): bool => $delivery['url'] === $inbox));
  }

  /**
   * The activities of a delivery, as an array whether it batched or not.
   *
   * @param array{url: string, headers: array<string, string>, body: string, status: int} $delivery
   *   The delivery.
   *
   * @return list<array<string, mixed>>
   *   The activities.
   */
  protected function activities(array $delivery): array {
    $notification = json_decode($delivery['body'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertIsArray($notification);
    $activity = $notification['activity'];
    $this->assertIsArray($activity);
    return array_is_list($activity) ? $activity : [$activity];
  }

  /**
   * Verifies a delivery as an inbox would, with the published key.
   *
   * @param array{url: string, headers: array<string, string>, body: string, status: int} $delivery
   *   The delivery.
   */
  protected function verify(array $delivery): VerifiedNotification {
    $verifier = new WebhookVerifier(
      storageDescriptionResolver: function (string $url): StorageDescription {
        $this->assertSame(self::STORAGE, $url);
        $response = $this->send('GET', '/lws/alice/', ['Accept' => 'application/lws+cid']);
        return StorageDescription::parse(json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR), $url);
      },
      trustedStorages: [self::STORAGE],
    );
    return $verifier->verify('POST', $delivery['url'], $delivery['headers'], $delivery['body']);
  }

}
