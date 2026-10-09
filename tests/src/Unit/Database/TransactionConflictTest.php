<?php

declare(strict_types=1);

namespace Drupal\Tests\lws\Unit\Database;

use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\lws\Database\TransactionConflict;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests recognizing a transaction the database gave up on.
 */
#[CoversClass(TransactionConflict::class)]
#[Group('lws')]
final class TransactionConflictTest extends UnitTestCase {

  /**
   * A PDO exception with the error information a driver gives.
   *
   * @param array{string, int|null, string} $info
   *   SQLSTATE, the driver's error code, and its message.
   */
  public static function pdo(array $info): \PDOException {
    $exception = new \PDOException(sprintf('SQLSTATE[%s]: %s', $info[0], $info[2]));
    $exception->errorInfo = $info;
    return $exception;
  }

  /**
   * Errors, and whether each is a conflict.
   *
   * @return array<string, array{array{string, int|null, string}, bool}>
   *   Error information and the verdict.
   */
  public static function errors(): array {
    return [
      'MySQL deadlock' => [['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'], TRUE],
      'MySQL lock wait timeout' => [['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'], TRUE],
      'PostgreSQL serialization failure' => [['40001', 7, 'could not serialize access due to concurrent update'], TRUE],
      'PostgreSQL deadlock' => [['40P01', 7, 'deadlock detected'], TRUE],
      'duplicate key' => [['23000', 1062, "Duplicate entry 'a' for key 'name'"], FALSE],
      'value out of range' => [['22003', 1264, "Out of range value for column 'not_after' at row 1"], FALSE],
      'SQLite busy' => [['HY000', 5, 'database is locked'], FALSE],
    ];
  }

  /**
   * Tests the verdict on each error, bare and as Drupal wraps it.
   *
   * @param array{string, int|null, string} $info
   *   The error information.
   * @param bool $conflict
   *   Whether it is a conflict.
   */
  #[DataProvider('errors')]
  public function testErrors(array $info, bool $conflict): void {
    $pdo = self::pdo($info);
    $this->assertSame($conflict, TransactionConflict::is($pdo));
    $wrapped = new DatabaseExceptionWrapper($pdo->getMessage(), 0, $pdo);
    $this->assertSame($conflict, TransactionConflict::is($wrapped));
    // An entity save wraps the database's exception once more.
    $this->assertSame($conflict, TransactionConflict::is(new EntityStorageException($wrapped->getMessage(), 0, $wrapped)));
  }

  /**
   * Tests exceptions without database error information.
   */
  public function testOtherExceptions(): void {
    $this->assertFalse(TransactionConflict::is(new \RuntimeException('Deadlock found')));
    $this->assertFalse(TransactionConflict::is(new IntegrityConstraintViolationException('Duplicate entry')));
    // Without errorInfo, the SQLSTATE is the code.
    $this->assertFalse(TransactionConflict::is(new \PDOException('no information')));
  }

}
