<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_storage\Kernel;

use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\file\FileInterface;
use Drupal\lws\Database\TransactionConflict;
use Drupal\lws\Http\LwsHttpException;
use Drupal\lws_storage\Entity\LwsResourceInterface;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\LwsResourceEvent;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests writes beside concurrent ones: conflicts, rollbacks and subtrees.
 *
 * A deadlock cannot be made in one process, so the work of an operation
 * throws the exception a deadlock gives, through its precondition.
 */
#[Group('lws')]
#[RunTestsInSeparateProcesses]
final class ConcurrencyTest extends LwsStorageKernelTestBase {

  /**
   * The storage.
   */
  private LwsStorageInterface $storage;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->storage = $this->storages->createStorage('alice', 'Alice', ['https://id.example/alice'], quotaBytes: 100);
  }

  /**
   * The exception MySQL gives a transaction it rolled back to break a deadlock.
   */
  private static function deadlock(): DatabaseExceptionWrapper {
    $pdo = new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
    $pdo->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];
    return new DatabaseExceptionWrapper($pdo->getMessage(), 0, $pdo);
  }

  /**
   * A count the test's callbacks share.
   *
   * @return \ArrayObject<string, int>
   *   The count, as "n".
   */
  private static function counter(): \ArrayObject {
    return new \ArrayObject(['n' => 0]);
  }

  /**
   * A readable stream of bytes.
   *
   * @return resource
   *   The stream.
   */
  private static function stream(string $content) {
    $stream = fopen('php://memory', 'w+b');
    self::assertNotFalse($stream);
    fwrite($stream, $content);
    rewind($stream);
    return $stream;
  }

  /**
   * Creates a data resource in the root container.
   */
  private function create(string $name, string $content): LwsResourceInterface {
    return $this->storages->createResource($this->resources->root($this->storage), $name, FALSE, self::stream($content), 'text/plain');
  }

  /**
   * The resource as it is in the database.
   */
  private function reload(LwsResourceInterface $resource): LwsResourceInterface {
    $current = $this->container->get('entity_type.manager')->getStorage('lws_resource')->loadUnchanged((int) $resource->id());
    $this->assertInstanceOf(LwsResourceInterface::class, $current);
    return $current;
  }

  /**
   * The bytes of a resource's content.
   */
  private function contentOf(LwsResourceInterface $resource): string {
    $uri = (string) $this->reload($resource)->getContentFile()?->getFileUri();
    return (string) file_get_contents($uri);
  }

  /**
   * The storage's used bytes, as the database has them.
   */
  private function usedBytes(): int {
    return (int) $this->container->get('database')->select('lws_storage', 's')
      ->fields('s', ['used_bytes'])
      ->condition('id', $this->storage->id())
      ->execute()?->fetchField();
  }

  /**
   * The names of the content files under the storage's directory.
   *
   * @return list<string>
   *   File names.
   */
  private function contentFiles(): array {
    $files = [];
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->siteDirectory . '/private', \FilesystemIterator::SKIP_DOTS)) as $file) {
      if ($file->isFile() && $file->getFilename() !== '.htaccess') {
        $files[] = $file->getFilename();
      }
    }
    sort($files);
    return $files;
  }

  /**
   * Tests that an operation the database gave up on runs again.
   */
  public function testConflictIsTriedAgain(): void {
    $resource = $this->create('a.txt', 'first');
    $previous = $this->reload($resource)->getContentFile();
    $this->assertInstanceOf(FileInterface::class, $previous);
    $updates = self::counter();
    $this->container->get('event_dispatcher')->addListener(LwsResourceEvent::UPDATED, function () use ($updates): void {
      $updates['n']++;
    });

    $calls = self::counter();
    $current = $this->storages->replaceContent($resource, self::stream('second'), NULL, function () use ($calls): void {
      if (++$calls['n'] === 1) {
        throw self::deadlock();
      }
    });
    $this->assertSame(2, $calls['n']);
    $this->assertSame('second', $this->contentOf($current));
    $this->assertSame(1, $updates['n'], 'The change is announced once.');
    $this->assertSame(6, $this->usedBytes(), 'The storage is charged once.');
    // The former content went, once the change committed.
    $this->assertNull($this->container->get('entity_type.manager')->getStorage('file')->load((int) $previous->id()));
    $this->assertCount(1, $this->contentFiles());

    // A change computed from the content runs again on what it finds then.
    /** @var \ArrayObject<int, string> $seen */
    $seen = new \ArrayObject();
    $calls = self::counter();
    $this->storages->changeContent($current, function (string $bytes) use ($seen): string {
      $seen[] = $bytes;
      return strtoupper($bytes);
    }, function () use ($calls): void {
      if (++$calls['n'] === 1) {
        throw self::deadlock();
      }
    });
    $this->assertSame(2, $calls['n']);
    $this->assertSame(['second'], $seen->getArrayCopy(), 'The second attempt changes the content it finds.');
    $this->assertSame('SECOND', $this->contentOf($current));
    $this->assertCount(1, $this->contentFiles(), 'No attempt leaves its content behind.');

    // So do deletes, metadata changes and creates.
    $calls = self::counter();
    $this->storages->changeMetadata($current, static fn (LwsResourceInterface $locked) => $locked->getUserMetadata(), function () use ($calls): void {
      if (++$calls['n'] === 1) {
        throw self::deadlock();
      }
    });
    $this->assertSame(2, $calls['n']);
    $calls = self::counter();
    $this->storages->deleteResource($current, FALSE, function () use ($calls): void {
      if (++$calls['n'] === 1) {
        throw self::deadlock();
      }
    });
    $this->assertSame(2, $calls['n']);
    $this->assertNull($this->container->get('entity_type.manager')->getStorage('lws_resource')->loadUnchanged((int) $current->id()));
    $this->assertSame(0, $this->usedBytes());
  }

  /**
   * Tests an operation that conflicts every time it runs.
   */
  public function testConflictsRunOut(): void {
    $resource = $this->create('a.txt', 'first');
    $files = $this->contentFiles();
    $calls = self::counter();
    try {
      $this->storages->replaceContent($resource, self::stream('second'), NULL, function () use ($calls): void {
        $calls['n']++;
        throw self::deadlock();
      });
      $this->fail('The conflict is thrown once the attempts run out.');
    }
    catch (DatabaseExceptionWrapper $e) {
      $this->assertTrue(TransactionConflict::is($e));
    }
    $this->assertSame(3, $calls['n']);
    $this->assertSame('first', $this->contentOf($resource));
    $this->assertSame(5, $this->usedBytes());
    $this->assertSame($files, $this->contentFiles(), 'The new bytes went; the old stayed.');
  }

  /**
   * Tests that an operation inside an outer transaction runs once.
   *
   * The database rolled back all of the outer transaction, which only its
   * owner can try again.
   */
  public function testNoSecondAttemptInsideAnOuterTransaction(): void {
    $resource = $this->create('a.txt', 'first');
    $calls = self::counter();
    $transaction = $this->container->get('database')->startTransaction();
    try {
      $this->storages->replaceContent($resource, self::stream('second'), NULL, function () use ($calls): void {
        $calls['n']++;
        throw self::deadlock();
      });
      $this->fail('The conflict is thrown at once.');
    }
    catch (DatabaseExceptionWrapper) {
      $transaction->rollBack();
    }
    $this->assertSame(1, $calls['n']);
    $this->assertSame('first', $this->contentOf($resource));
  }

  /**
   * Tests that a rolled-back change keeps the content it would have replaced.
   */
  public function testRollbackKeepsTheFormerContent(): void {
    $resource = $this->create('a.txt', 'first');
    $previous = (string) $this->reload($resource)->getContentFile()?->getFileUri();

    // The quota is charged last, after the resource points at the new
    // content: shrunk meanwhile, it refuses the change, which is rolled back.
    try {
      $this->storages->replaceContent($resource, self::stream('second'), NULL, function (): void {
        $this->container->get('database')->update('lws_storage')->fields(['quota_bytes' => 1])->condition('id', $this->storage->id())->execute();
      });
      $this->fail('The quota refuses the change.');
    }
    catch (LwsHttpException $e) {
      $this->assertSame(507, $e->getStatusCode());
    }
    $this->assertSame('first', $this->contentOf($resource));
    $this->assertFileExists($previous);

    // A change an outer transaction rolls back keeps it too, and so does a
    // delete.
    $transaction = $this->container->get('database')->startTransaction();
    $this->storages->replaceContent($resource, self::stream('second'), NULL);
    $transaction->rollBack();
    unset($transaction);
    $this->assertSame('first', $this->contentOf($resource));
    $this->assertFileExists($previous);

    $transaction = $this->container->get('database')->startTransaction();
    $this->storages->deleteResource($resource);
    $transaction->rollBack();
    unset($transaction);
    $this->assertSame('first', $this->contentOf($resource));
    $this->assertFileExists($previous);
  }

  /**
   * Tests that the descendants of a container are its subtree alone.
   *
   * They are found through each resource's parent: a sibling whose name
   * starts with the container's is not among them, nor is one of another
   * storage.
   */
  public function testDescendants(): void {
    $root = $this->resources->root($this->storage);
    $a = $this->storages->createContainer($root, 'a');
    $b = $this->storages->createContainer($a, 'b');
    $c = $this->storages->createResource($b, 'c.txt', FALSE, self::stream('c'), 'text/plain');
    $d = $this->storages->createResource($a, 'd.txt', FALSE, self::stream('d'), 'text/plain');
    $ab = $this->storages->createContainer($root, 'ab');
    $this->storages->createResource($ab, 'x.txt', FALSE, self::stream('x'), 'text/plain');
    $other = $this->storages->createStorage('bob', 'Bob');
    $this->storages->createContainer($this->storages->createContainer($this->resources->root($other), 'a'), 'b');

    $paths = static fn (array $resources): array => array_map(static fn (LwsResourceInterface $resource): string => $resource->getPath(), $resources);
    // Deepest first; at one depth, in reverse order of their paths.
    $this->assertSame(['root/a/b/c.txt', 'root/a/b/', 'root/a/d.txt'], $paths($this->resources->descendants($a)));
    $this->assertSame([$c->getPath()], $paths($this->resources->descendants($b)));
    $this->assertSame(3, $this->resources->countDescendants($a, 10));
    $this->assertSame(2, $this->resources->countDescendants($a, 2));
    $this->assertSame(6, $this->resources->countDescendants($root, 10));

    // Locked inside a transaction, the same.
    $transaction = $this->container->get('database')->startTransaction();
    $this->assertSame(['root/a/b/c.txt', 'root/a/b/', 'root/a/d.txt'], $paths($this->resources->descendants($a, TRUE)));
    unset($transaction);

    $this->storages->deleteResource($a, TRUE);
    $this->assertSame(['root/ab/x.txt', 'root/ab/'], $paths($this->resources->descendants($root)));
    $this->assertSame([], $this->resources->descendants($this->storages->createContainer($root, 'empty')));
    $this->assertNull($this->container->get('entity_type.manager')->getStorage('lws_resource')->loadUnchanged((int) $d->id()));
  }

  /**
   * Tests that a conflict in the LWS URL space is a 503 a client may retry.
   */
  public function testConflictResponse(): void {
    $request = Request::create(self::BASE . '/lws/alice/root/a.txt', 'PUT');
    $event = new ExceptionEvent($this->container->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST, self::deadlock());
    $this->container->get('lws.exception_subscriber')->onException($event);
    $response = $event->getResponse();
    $this->assertNotNull($response);
    $this->assertSame(503, $response->getStatusCode());
    $this->assertSame('1', $response->headers->get('Retry-After'));
    $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));
    $problem = json_decode((string) $response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertSame(503, $problem['status']);
    $this->assertStringContainsString('Try again', $problem['detail']);
  }

}
