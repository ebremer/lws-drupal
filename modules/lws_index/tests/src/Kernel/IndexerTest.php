<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_index\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\lws_index\Form\IndexSettingsForm;
use Drupal\lws_index\Indexer;
use Drupal\lws_storage\Entity\LwsResourceInterface;
use Drupal\lws_storage\Linkset\UserMetadata;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the index follows the resources, and its settings.
 */
#[Group('lws')]
#[RunTestsInSeparateProcesses]
final class IndexerTest extends IndexKernelTestBase {

  /**
   * What the index holds, as "path rel href" lines, sorted.
   *
   * @return list<string>
   *   The lines.
   */
  private function entries(): array {
    $query = $this->container->get('database')->select(Indexer::TABLE, 'l');
    $query->join('lws_resource', 'r', 'r.id = l.resource_id');
    $query->addField('r', 'path');
    $query->addField('l', 'rel');
    $query->addField('l', 'href');
    $lines = [];
    foreach ($query->execute()?->fetchAll() ?? [] as $row) {
      $href = str_replace([self::T, 'https://www.w3.org/ns/lws#'], ['t:', 'lws:'], $row->href);
      $lines[] = $row->path . ' ' . $row->rel . ' ' . $href;
    }
    sort($lines);
    return $lines;
  }

  /**
   * The number of entries in the index.
   */
  private function entryCount(): int {
    return (int) $this->container->get('database')->select(Indexer::TABLE)->countQuery()->execute()?->fetchField();
  }

  /**
   * Loads a resource by path.
   */
  private function resource(string $path): LwsResourceInterface {
    $resource = $this->resources->findByPath($this->loadStorage('alice'), $path);
    $this->assertNotNull($resource);
    return $resource;
  }

  /**
   * Tests the entries of resources as they are created, changed and deleted.
   */
  public function testEntries(): void {
    $this->assertSame(['root/ type lws:Container'], $this->entries());
    $folder = $this->createContainer('root/', 'folder', ['Group']);
    $this->create($folder, 'one', ['Alpha'], [
      'DescribedBy' => 'https://shapes.example/one',
      'https://rels.example/X' => 'urn:x',
    ]);
    $this->assertSame([
      'root/ type lws:Container',
      'root/folder/ type lws:Container',
      'root/folder/ type t:Group',
      'root/folder/one describedby https://shapes.example/one',
      'root/folder/one https://rels.example/X urn:x',
      'root/folder/one type lws:DataResource',
      'root/folder/one type t:Alpha',
    ], $this->entries());

    // A change to the content leaves the entries; one to the metadata
    // changes them.
    $one = $this->resource('root/folder/one');
    $this->storages->changeMetadata($one, static fn (LwsResourceInterface $current) => new UserMetadata(['https://types.example/#Beta'], ['license' => [['href' => 'https://licenses.example/cc0']]]));
    $this->assertSame([
      'root/ type lws:Container',
      'root/folder/ type lws:Container',
      'root/folder/ type t:Group',
      'root/folder/one license https://licenses.example/cc0',
      'root/folder/one type lws:DataResource',
      'root/folder/one type t:Beta',
    ], $this->entries());

    // A change that rolls back takes its entries with it.
    $transaction = $this->container->get('database')->startTransaction();
    $this->storages->createResource($this->resource('root/'), 'gone', TRUE, NULL, '', NULL, new UserMetadata([self::T . 'Gamma']));
    $this->assertContains('root/gone/ type t:Gamma', $this->entries());
    $transaction->rollBack();
    unset($transaction);
    $this->assertNotContains('root/gone/ type t:Gamma', $this->entries());

    // A rebuild makes the same entries.
    $before = $this->entries();
    $this->assertSame(3, $this->container->get('lws_index.indexer')->rebuild());
    $this->assertSame($before, $this->entries());

    // A recursive delete removes the entries of every resource it deletes.
    $this->storages->deleteResource($this->resource($folder), TRUE);
    $this->assertSame(['root/ type lws:Container'], $this->entries());

    // As does deleting the storage.
    $this->create('root/', 'two', ['Alpha']);
    $this->storages->createStorage('bob', 'Bob', [self::BOB]);
    $this->assertSame(4, $this->entryCount());
    $this->loadStorage('alice')->delete();
    $this->assertSame(1, $this->entryCount());
  }

  /**
   * Tests the settings form.
   */
  public function testSettings(): void {
    $submit = function (string $relations): array {
      $state = (new FormState())->setValues(['relations' => $relations]);
      $this->container->get('form_builder')->submitForm(IndexSettingsForm::class, $state);
      return array_values(array_map('strval', $state->getErrors()));
    };
    $this->assertSame([], $submit("DescribedBy\n\n  license \nhttps://rels.example/X\ndescribedby"));
    $this->assertSame(['describedby', 'license', 'https://rels.example/X'], $this->config('lws_index.settings')->get('relations'));

    $errors = $submit("describedby\nup");
    $this->assertCount(1, $errors);
    $this->assertStringContainsString('structural', $errors[0]);
    $this->assertCount(1, $submit('not a relation'));
    // Nothing was saved. (The form changed the factory's editable copy.)
    $this->assertSame(['describedby', 'license', 'https://rels.example/X'], $this->container->get('config.storage')->read('lws_index.settings')['relations'] ?? NULL);
    $this->assertSame([], $submit(''));
    $this->assertSame([], $this->config('lws_index.settings')->get('relations'));
  }

}
