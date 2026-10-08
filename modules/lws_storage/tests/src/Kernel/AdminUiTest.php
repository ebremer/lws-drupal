<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_storage\Kernel;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AccountInterface;
use Drupal\lws_storage\Entity\LwsResourceInterface;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\Form\LwsStorageDeleteForm;
use Drupal\lws_storage\Form\ResourceMediaForm;
use Drupal\lws_storage\Form\StorageSettingsForm;
use Drupal\lws_storage\Hook\LwsStorageHooks;
use Drupal\media\MediaInterface;
use Drupal\Tests\HttpKernelUiHelperTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the administration pages of storages (DESIGN.md §5.7).
 */
#[Group('lws')]
#[RunTestsInSeparateProcesses]
final class AdminUiTest extends LwsStorageKernelTestBase {

  use HttpKernelUiHelperTrait;
  use MediaTypeCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['field'];

  /**
   * A storage administrator.
   */
  private AccountInterface $admin;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('user', ['users_data']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    // User 1 is special; nobody here should be.
    $this->createUser();
    $this->admin = $this->createUser(['administer lws storages', 'administer lws']);
  }

  /**
   * Submits a form as the current user, with the form builder.
   *
   * The kernel's browser keeps no session between requests, so forms with
   * tokens cannot be posted through it: pages are read through it, and forms
   * submitted here.
   *
   * @param \Drupal\Core\Form\FormInterface|string $form
   *   The form object or class.
   * @param array<string, mixed> $values
   *   The submitted values.
   * @param mixed ...$arguments
   *   Arguments for the form's buildForm().
   *
   * @return list<string>
   *   The validation errors.
   */
  private function submit(FormInterface|string $form, array $values, mixed ...$arguments): array {
    $state = (new FormState())->setValues($values);
    $this->container->get('form_builder')->submitForm($form, $state, ...$arguments);
    $messages = $this->container->get('messenger')->deleteByType('error');
    return array_values(array_map('strval', [...$state->getErrors(), ...$messages]));
  }

  /**
   * The add or edit form of a storage.
   */
  private function storageForm(EntityInterface $storage, string $operation): FormInterface {
    $form = $this->container->get('entity_type.manager')->getFormObject('lws_storage', $operation);
    $form->setEntity($storage);
    return $form;
  }

  /**
   * Starts a new browser on the current container, after modules changed.
   */
  private function restartBrowser(): void {
    $this->mink = NULL;
  }

  /**
   * Creates a data resource in a storage's root.
   */
  private function addResource(LwsStorageInterface $storage, string $name, string $content, string $mediaType = 'text/plain'): LwsResourceInterface {
    $root = $this->resources->findByPath($storage, 'root/');
    $this->assertNotNull($root);
    $body = fopen('php://memory', 'w+b');
    $this->assertIsResource($body);
    fwrite($body, $content);
    rewind($body);
    return $this->storages->createResource($root, $name, FALSE, $body, $mediaType);
  }

  /**
   * Tests adding, editing and deleting a storage.
   */
  public function testStorageForms(): void {
    $this->setCurrentUser($this->admin);
    $this->drupalGet('/admin/content/lws/add');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('quota');
    $type = $this->container->get('entity_type.manager')->getStorage('lws_storage');
    $errors = $this->submit($this->storageForm($type->create(), 'add'), [
      'label' => [['value' => 'Alice']],
      'slug' => [['value' => 'alice']],
      'controllers' => [['value' => 'https://id.example/alice']],
      'authorization_server' => 'test',
      'quota' => '10 MB',
      'page_size' => '50',
      'require_if_match' => '1',
      'status' => ['value' => 1],
      'op' => 'Save',
    ]);
    $this->assertSame([], $errors);
    $storage = $this->loadStorage('alice');
    $this->assertSame(['https://id.example/alice'], $storage->getControllers());
    $this->assertSame('test', $storage->getAuthorizationServerId());
    $this->assertSame(10485760, $storage->getQuotaBytes());
    $this->assertSame(50, $storage->getPageSize());
    $this->assertTrue($storage->requiresIfMatch());
    $this->assertNotNull($this->resources->findByPath($storage, 'root/'));

    // A taken or malformed slug, and a quota that is no size, are refused.
    $errors = $this->submit($this->storageForm($type->create(), 'add'), [
      'label' => [['value' => 'Again']],
      'slug' => [['value' => 'alice']],
      'op' => 'Save',
    ]);
    $this->assertStringContainsString('already exists', implode(' ', $errors));
    $errors = $this->submit($this->storageForm($type->create(), 'add'), [
      'label' => [['value' => 'Bad']],
      'slug' => [['value' => 'Not A Slug']],
      'quota' => 'lots',
      'op' => 'Save',
    ]);
    $this->assertStringContainsString('A slug is 1 to 63', implode(' ', $errors));
    $this->assertStringContainsString('Give the quota as a size', implode(' ', $errors));

    // The slug stays; the quota shows as it was set, and saving keeps it.
    $this->drupalGet('/admin/content/lws/' . $storage->id());
    $this->assertSession()->fieldDisabled('slug[0][value]');
    $this->assertSession()->fieldValueEquals('quota', '10 MB');
    $this->assertSession()->pageTextContains(self::BASE . '/lws/alice/');
    $errors = $this->submit($this->storageForm($storage, 'edit'), [
      'label' => [['value' => 'Alice B.']],
      'slug' => [['value' => 'alice']],
      'controllers' => [['value' => 'https://id.example/alice']],
      'authorization_server' => 'test',
      'quota' => '10 MB',
      'page_size' => '50',
      'require_if_match' => '',
      'status' => ['value' => 1],
      'op' => 'Save',
    ]);
    $this->assertSame([], $errors);
    $storage = $this->loadStorage('alice');
    $this->assertSame('Alice B.', $storage->label());
    $this->assertSame(10485760, $storage->getQuotaBytes());
    $this->assertNull($storage->requiresIfMatch());
    // Whatever a form says, a storage keeps its slug.
    $storage->set('slug', 'bob');
    try {
      $storage->save();
      $this->fail('A storage was renamed.');
    }
    catch (EntityStorageException $e) {
      $this->assertInstanceOf(\LogicException::class, $e->getPrevious());
    }

    $this->drupalGet('/admin/content/lws');
    $this->assertSession()->pageTextContains('Alice B.');
    $this->assertSession()->pageTextContains(self::BASE . '/lws/alice/');
    $this->assertSession()->linkByHrefExists('/admin/content/lws/' . $storage->id() . '/access');
    $this->assertSession()->linkByHrefExists('/admin/content/lws/' . $storage->id() . '/resources');

    $this->drupalGet('/admin/content/lws/' . $storage->id() . '/delete');
    $this->assertSession()->pageTextContains('This cannot be undone');
    $delete = $this->container->get('entity_type.manager')->getFormObject('lws_storage', 'delete');
    $delete->setEntity($this->loadStorage('alice'));
    $this->assertSame([], $this->submit($delete, []));
    $this->assertNull($type->loadUnchanged((int) $storage->id()));
    $this->assertSame(0, (int) $this->container->get('entity_type.manager')->getStorage('lws_resource')->getQuery()->accessCheck(FALSE)->count()->execute());
  }

  /**
   * Tests who sees which storages.
   */
  public function testAccess(): void {
    $owner = $this->createUser(['manage own lws storages']);
    $mine = $this->storages->createStorage('mine', 'Mine', [], (int) $owner->id());
    $theirs = $this->storages->createStorage('theirs', 'Theirs');

    $this->setCurrentUser($owner);
    $this->drupalGet('/admin/content/lws');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Mine');
    $this->assertSession()->pageTextNotContains('Theirs');
    $this->drupalGet('/admin/content/lws/' . $mine->id() . '/access');
    $this->assertSession()->statusCodeEquals(200);
    foreach (['', '/delete', '/resources'] as $page) {
      $this->drupalGet('/admin/content/lws/' . $mine->id() . $page);
      $this->assertSession()->statusCodeEquals(403);
    }
    $this->drupalGet('/admin/content/lws/' . $theirs->id() . '/access');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('/admin/content/lws/add');
    $this->assertSession()->statusCodeEquals(403);

    $this->setCurrentUser($this->createUser());
    $this->drupalGet('/admin/content/lws');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests the storage settings form.
   */
  public function testSettings(): void {
    $this->setCurrentUser($this->admin);
    $values = [
      'scheme' => 'private',
      'max_upload_bytes' => '100 MB',
      'max_recursive_delete' => '50',
      'page_size' => '20',
      'require_if_match' => 0,
    ];
    $this->assertSame([], $this->submit(StorageSettingsForm::class, $values));
    $settings = $this->config('lws_storage.settings');
    $this->assertSame(104857600, $settings->get('max_upload_bytes'));
    $this->assertSame(50, $settings->get('max_recursive_delete'));
    $this->assertSame(20, $settings->get('page_size'));
    $this->drupalGet('/admin/config/services/lws/storage');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldValueEquals('max_upload_bytes', '100 MB');
    $this->assertNotSame([], $this->submit(StorageSettingsForm::class, ['page_size' => '5000'] + $values));
    $this->assertNotSame([], $this->submit(StorageSettingsForm::class, ['max_upload_bytes' => 'huge'] + $values));
    // As saved, not as the refused forms left the configuration object.
    $saved = $this->container->get('config.storage')->read('lws_storage.settings');
    $this->assertSame(20, $saved['page_size'] ?? NULL);
    $this->assertSame(104857600, $saved['max_upload_bytes'] ?? NULL);
  }

  /**
   * Tests deleting a large storage in a batch.
   */
  public function testBatchDelete(): void {
    $storage = $this->storages->createStorage('big', 'Big');
    $this->addResource($storage, 'a.txt', 'a');
    $this->addResource($storage, 'b.txt', 'b');
    $context = [];
    do {
      LwsStorageDeleteForm::deleteResources((int) $storage->id(), $context);
    } while ($context['finished'] < 1);
    $this->assertSame(0, (int) $this->container->get('entity_type.manager')->getStorage('lws_resource')->getQuery()->accessCheck(FALSE)->count()->execute());
    LwsStorageDeleteForm::deleteStorage((int) $storage->id(), $context);
    $this->assertNull($this->container->get('entity_type.manager')->getStorage('lws_storage')->loadUnchanged((int) $storage->id()));
    $this->assertSame(2, $this->container->get('queue')->get('lws_storage_gc')->numberOfItems());
  }

  /**
   * Tests the resource browser, with and without Views.
   */
  public function testBrowser(): void {
    $storage = $this->storages->createStorage('alice', 'Alice');
    $resource = $this->addResource($storage, 'notes.txt', 'some notes');
    $this->setCurrentUser($this->admin);
    $this->drupalGet('/admin/content/lws/' . $storage->id() . '/resources');
    $this->assertSession()->pageTextContains('needs the Views module');

    $this->enableModules(['views']);
    $this->container->get('config.installer')->installOptionalConfig();
    $this->container->get('router.builder')->rebuild();
    $this->restartBrowser();
    $this->drupalGet('/admin/content/lws/' . $storage->id() . '/resources');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('root/notes.txt');
    $this->assertSession()->pageTextContains('text/plain');
    $this->assertSession()->linkExists('Download');
    $this->drupalGet('/admin/content/lws/' . $storage->id() . '/resources', ['query' => ['path' => 'root/elsewhere']]);
    $this->assertSession()->pageTextNotContains('root/notes.txt');

    // Downloads are attachments, in a sandbox.
    $uri = (string) $resource->getContentFile()?->getFileUri();
    $hooks = $this->container->get(LwsStorageHooks::class);
    $this->assertInstanceOf(LwsStorageHooks::class, $hooks);
    $headers = $hooks->fileDownload($uri);
    $this->assertIsArray($headers);
    $this->assertSame('attachment; filename=notes.txt', $headers['Content-Disposition']);
    $this->assertSame('sandbox', $headers['Content-Security-Policy']);
  }

  /**
   * Tests making a Media item from a resource.
   */
  public function testCreateMediaItem(): void {
    $storage = $this->storages->createStorage('alice', 'Alice');
    $resource = $this->addResource($storage, 'report', 'The report.');
    $page = '/admin/content/lws/' . $storage->id() . '/resources/' . $resource->id() . '/media';
    $this->setCurrentUser($this->admin);
    // Without Media, there is no such page.
    $this->drupalGet($page);
    $this->assertSession()->statusCodeEquals(403);

    $this->enableModules(['image', 'media']);
    $this->installEntitySchema('media');
    $this->installConfig(['field', 'image', 'media']);
    $this->createMediaType('file', ['id' => 'document', 'label' => 'Document']);
    $this->container->get('router.builder')->rebuild();
    $this->restartBrowser();
    // As for any media item with a public file, the user must be able to see
    // public files.
    $this->setCurrentUser($this->createUser(['administer lws storages', 'administer media', 'access content']));
    $this->drupalGet($page);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->checkboxChecked('edit-bundle-document');
    $this->assertSame([], $this->submit(ResourceMediaForm::class, ['bundle' => 'document', 'name' => 'Quarterly report'], $storage, $resource));

    $media = $this->container->get('entity_type.manager')->getStorage('media')->loadByProperties(['name' => 'Quarterly report']);
    $media = reset($media);
    $this->assertInstanceOf(MediaInterface::class, $media);
    $this->assertFalse($media->isPublished());
    $this->assertTrue($media->getOwnerId() > 0);
    $fid = $media->getSource()->getSourceFieldValue($media);
    $copy = $this->container->get('entity_type.manager')->getStorage('file')->load($fid);
    $this->assertNotNull($copy);
    // A copy, with an extension for its type, outside LWS content.
    $this->assertSame('report.txt', $copy->getFilename());
    $this->assertStringStartsNotWith('private://lws/', (string) $copy->getFileUri());
    $this->assertSame('The report.', file_get_contents((string) $copy->getFileUri()));
    $this->assertNotSame($resource->getContentFile()?->id(), $copy->id());
    $this->assertTrue($copy->isPermanent());
  }

}
