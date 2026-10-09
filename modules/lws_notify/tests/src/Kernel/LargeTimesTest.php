<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_notify\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the columns of times that may lie past 2038, and their updates.
 *
 * A policy's not_after (lws_authz) and a subscription's expires (lws_notify)
 * are big integers: MySQL and MariaDB refuse a time past 2038 for a normal
 * one. Sites installed before get them through an update.
 */
#[Group('lws')]
#[RunTestsInSeparateProcesses]
final class LargeTimesTest extends NotifyKernelTestBase {

  /**
   * Each column: entity type and table, field, module, and update function.
   */
  private const COLUMNS = [
    ['lws_policy', 'not_after', 'lws_authz', 'lws_authz_update_11004'],
    ['lws_subscription', 'expires', 'lws_notify', 'lws_notify_update_11001'],
  ];

  /**
   * The column's type, as MySQL or MariaDB has it; NULL on other databases.
   */
  private function mysqlType(string $table, string $column): ?string {
    $database = $this->container->get('database');
    if ($database->driver() !== 'mysql') {
      return NULL;
    }
    return (string) $database->query('SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column', [
      ':table' => $database->getPrefix() . $table,
      ':column' => $column,
    ])?->fetchField();
  }

  /**
   * Tests a new site's columns, and the update of an older site's.
   */
  public function testColumns(): void {
    $installed = $this->container->get('keyvalue')->get('entity.storage_schema.sql');
    $database = $this->container->get('database');
    $updates = $this->container->get('entity.definition_update_manager');
    $mysql = $database->driver() === 'mysql';
    foreach (self::COLUMNS as [$table, $field, $module, $update]) {
      $key = $table . '.field_schema_data.' . $field;
      // A new site has big integers.
      $this->assertSame('big', $installed->get($key)[$table]['fields'][$field]['size']);
      $this->assertSame($mysql ? 'bigint' : NULL, $this->mysqlType($table, $field));

      // A site installed before has a normal integer, which the storage
      // schema no longer matches.
      $schema = $installed->get($key);
      $schema[$table]['fields'][$field]['size'] = 'normal';
      if ($database->driver() !== 'sqlite') {
        $database->schema()->changeField($table, $field, $field, $schema[$table]['fields'][$field]);
      }
      $installed->set($key, $schema);
      $this->assertSame($mysql ? 'int' : NULL, $this->mysqlType($table, $field));
      $this->assertArrayHasKey($table, $updates->getChangeList());

      $this->container->get('module_handler')->loadInclude($module, 'install');
      $this->assertStringContainsString('now', $update());
      $this->assertSame('big', $installed->get($key)[$table]['fields'][$field]['size']);
      $this->assertSame($mysql ? 'bigint' : NULL, $this->mysqlType($table, $field));
      $this->assertArrayNotHasKey($table, $updates->getChangeList());
      // Run again, it changes nothing.
      $this->assertStringContainsString('already', $update());
    }

    // Both hold a time past 2038.
    $this->config('lws_notify.settings')->set('limits.lifetime', 0)->save();
    $response = $this->subscribe(self::ALICE, ['root/'], ['expires' => '2999-12-31T23:59:59Z']);
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    $expires = $this->container->get('database')->select('lws_subscription', 's')->fields('s', ['expires'])->execute()?->fetchCol();
    // 2999-12-31T23:59:59Z.
    $this->assertSame(['32503679999'], array_map('strval', $expires ?? []));
  }

}
