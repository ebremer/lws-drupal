<?php

declare(strict_types=1);

namespace Drupal\lws\Database;

/**
 * Recognizes a transaction the database gave up on because of another one.
 *
 * MySQL and MariaDB break a deadlock by rolling back one of its transactions
 * (error 1213, SQLSTATE 40001), and stop waiting for a lock after
 * innodb_lock_wait_timeout (error 1205). PostgreSQL reports serialization
 * failures (40001) and deadlocks (40P01). Neither is the request's fault: the
 * same work, done again, usually succeeds.
 */
final class TransactionConflict {

  /**
   * MySQL's and MariaDB's errors for a deadlock and a lock wait timeout.
   */
  private const MYSQL_ERRORS = [1213, 1205];

  /**
   * The SQLSTATEs of a serialization failure and of a PostgreSQL deadlock.
   */
  private const SQLSTATES = ['40001', '40P01'];

  /**
   * Whether an exception, or one it wraps, is such a conflict.
   */
  public static function is(\Throwable $exception): bool {
    for ($current = $exception; $current !== NULL; $current = $current->getPrevious()) {
      if (!$current instanceof \PDOException) {
        continue;
      }
      $info = $current->errorInfo ?? [];
      $sqlState = (string) ($info[0] ?? $current->getCode());
      if (in_array($sqlState, self::SQLSTATES, TRUE) || in_array((int) ($info[1] ?? 0), self::MYSQL_ERRORS, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
