<?php

/**
 * @file
 * Makes a storage for the listing benchmark; see README.md.
 *
 * Run with drush php:script. The storage "perf" is controlled by
 * https://id.example/perf; its container big/ holds 10,000 data resources,
 * alternately text/plain and application/json. https://id.example/viewer may
 * read the containers, and the text alone of the data resources.
 */

declare(strict_types=1);

use Drupal\lws_authz\Policy\AccessPolicy;
use Drupal\lws_authz\Policy\Constraint;
use Drupal\lws_storage\Entity\LwsResourceInterface;
use Drupal\lws_storage\Entity\LwsStorageInterface;

$members = 10000;
$manager = \Drupal::service('lws_storage.storage_manager');
$resources = \Drupal::service('lws_storage.resource_repository');
$entityTypeManager = \Drupal::entityTypeManager();
$existing = $entityTypeManager->getStorage('lws_storage')->loadByProperties(['slug' => 'perf']);
$storage = reset($existing) ?: $manager->createStorage('perf', 'Performance', ['https://id.example/perf']);
assert($storage instanceof LwsStorageInterface);
$root = $resources->findByPath($storage, 'root/');
assert($root instanceof LwsResourceInterface);
$big = $resources->findByPath($storage, 'root/big/') ?? $manager->createContainer($root, 'big');
$count = static fn (): int => (int) \Drupal::database()->query('SELECT COUNT(*) FROM {lws_resource} WHERE [parent] = :parent', [':parent' => $big->id()])?->fetchField();

$start = microtime(TRUE);
for ($i = $count() + 1; $i <= $members; $i++) {
  $body = fopen('php://memory', 'w+b');
  assert(is_resource($body));
  fwrite($body, sprintf('{"item":%d,"text":"%s"}', $i, str_repeat('x', 16)));
  rewind($body);
  $manager->createResource($big, sprintf('item-%05d.txt', $i), FALSE, $body, $i % 2 ? 'text/plain' : 'application/json');
  if ($i % 1000 === 0) {
    printf("%d created, %.1f s\n", $i, microtime(TRUE) - $start);
    // Keep memory flat.
    $entityTypeManager->getStorage('lws_resource')->resetCache();
    $entityTypeManager->getStorage('file')->resetCache();
  }
}

$ref = \Drupal::service('lws_storage.storage_registry')->ref($storage);
$policies = \Drupal::service('lws_authz.policy_store');
if ($policies->forAssignees((int) $storage->id(), ['https://id.example/viewer']) === []) {
  $parser = \Drupal::service('lws_authz.policy_parser');
  $policies->add($ref, $parser->parse(AccessPolicy::document('https://id.example/viewer', ['read'], 'Container', [$ref->uri . 'root/']), $ref));
  $text = AccessPolicy::document('https://id.example/viewer', ['read'], 'DataResource', [$ref->uri . 'root/']);
  $text['constraint'] = [(new Constraint('format', 'eq', 'text/plain'))->toJson()];
  $policies->add($ref, $parser->parse($text, $ref));
}
printf("%sbig/ holds %d members.\n", $ref->uri . 'root/', $count());
