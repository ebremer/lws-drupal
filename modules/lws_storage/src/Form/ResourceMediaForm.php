<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\file\FileRepositoryInterface;
use Drupal\file\Plugin\Field\FieldType\FileItem;
use Drupal\lws_storage\Entity\LwsResourceInterface;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\ResourceLinks;
use Drupal\media\MediaTypeInterface;
use Drupal\media\Plugin\media\Source\File;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Mime\MimeTypes;

/**
 * Makes a Media item from the content of a data resource (DESIGN.md §5.7).
 *
 * The content is copied into the media type's own file location. The copy is
 * the site's, and the item starts unpublished: publishing it is an editorial
 * act, which no later change to the resource or its access affects.
 */
final class ResourceMediaForm extends FormBase {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileRepositoryInterface $fileRepository,
    protected FileSystemInterface $fileSystem,
    protected ResourceLinks $links,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('file.repository'),
      $container->get('file_system'),
      $container->get('lws_storage.resource_links'),
    );
  }

  /**
   * Who may make media items from resources: storage administrators.
   *
   * Only with the Media module, and only from data resources of the storage
   * in the URL.
   */
  public static function access(AccountInterface $account, LwsStorageInterface $lws_storage, LwsResourceInterface $lws_resource): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'administer lws storages')
      ->andIf(AccessResult::allowedIf(
        \Drupal::moduleHandler()->moduleExists('media')
          && $lws_resource->getLwsStorageId() === (int) $lws_storage->id()
          && $lws_resource->getContentFile() !== NULL,
      ))
      ->addCacheTags(['config:core.extension'])
      ->addCacheableDependency($lws_resource);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lws_storage_resource_media';
  }

  /**
   * The media types made from a file, that the user may create.
   *
   * @return array<string, \Drupal\media\MediaTypeInterface>
   *   The media types, by ID.
   */
  private function mediaTypes(): array {
    $types = [];
    $access = $this->entityTypeManager->getAccessControlHandler('media');
    foreach ($this->entityTypeManager->getStorage('media_type')->loadMultiple() as $id => $type) {
      if ($type->status() && $type->getSource() instanceof File && $access->createAccess((string) $id)) {
        $types[(string) $id] = $type;
      }
    }
    return $types;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\lws_storage\Entity\LwsStorageInterface|null $lws_storage
   *   The storage.
   * @param \Drupal\lws_storage\Entity\LwsResourceInterface|null $lws_resource
   *   The data resource.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?LwsStorageInterface $lws_storage = NULL, ?LwsResourceInterface $lws_resource = NULL): array {
    assert($lws_storage !== NULL && $lws_resource !== NULL);
    $form_state->set('storage', $lws_storage);
    $form_state->set('resource', $lws_resource);
    $form['resource'] = [
      '#type' => 'item',
      '#title' => $this->t('Resource'),
      '#markup' => $this->links->uri($lws_storage, $lws_resource),
      '#description' => $this->t('@type, @size', [
        '@type' => (string) $lws_resource->getMediaType(),
        '@size' => ByteSizeMarkup::create((int) $lws_resource->getSize()),
      ]),
    ];
    $types = $this->mediaTypes();
    if ($types === []) {
      $form['none'] = ['#markup' => $this->t('There is no media type made from a file that you may create.')];
      return $form;
    }
    $form['bundle'] = [
      '#type' => 'radios',
      '#title' => $this->t('Media type'),
      '#options' => array_map(static fn (MediaTypeInterface $type): string => (string) $type->label(), $types),
      '#default_value' => $this->suggestedType($types, (string) $lws_resource->getMediaType()),
      '#required' => TRUE,
    ];
    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $lws_resource->getName(),
      '#maxlength' => 255,
      '#required' => TRUE,
    ];
    $form['help'] = [
      '#markup' => '<p>' . $this->t('The content is copied, and the media item starts unpublished. Later changes to the resource, or to who may access it, do not change the media item, and the media item does not change the resource.') . '</p>',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Create media item')];
    return $form;
  }

  /**
   * The media type that fits a media type of content best.
   *
   * @param array<string, \Drupal\media\MediaTypeInterface> $types
   *   The media types.
   * @param string $mediaType
   *   The media type of the content.
   */
  private function suggestedType(array $types, string $mediaType): string {
    $extensions = MimeTypes::getDefault()->getExtensions(strtolower(trim(explode(';', $mediaType)[0])));
    foreach ($types as $id => $type) {
      $allowed = preg_split('/\s+/', (string) ($this->sourceField($type)?->getSetting('file_extensions') ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
      if (array_intersect($extensions, $allowed) !== []) {
        return $id;
      }
    }
    return (string) array_key_first($types);
  }

  /**
   * The source field of a media type.
   */
  private function sourceField(MediaTypeInterface $type): ?FieldDefinitionInterface {
    return $type->getSource()->getSourceFieldDefinition($type);
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $resource = $form_state->get('resource');
    $storage = $form_state->get('storage');
    assert($resource instanceof LwsResourceInterface && $storage instanceof LwsStorageInterface);
    $type = $this->mediaTypes()[(string) $form_state->getValue('bundle')] ?? NULL;
    $source = $resource->getContentFile();
    $field = $type === NULL ? NULL : $this->sourceField($type);
    if ($type === NULL || $source === NULL || $field === NULL) {
      $this->messenger()->addError($this->t('The media item could not be created.'));
      return;
    }

    $media = $this->entityTypeManager->getStorage('media')->create([
      'bundle' => $type->id(),
      'name' => (string) $form_state->getValue('name'),
      'uid' => $this->currentUser()->id(),
      'status' => FALSE,
    ]);
    $item = $media->get($field->getName())->appendItem();
    assert($item instanceof FileItem);
    $directory = (string) $item->getUploadLocation();
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      $this->messenger()->addError($this->t('The media item could not be created: its files cannot be stored.'));
      return;
    }
    // The copy is the user's, and temporary until the media item is saved,
    // which makes it permanent, as an upload through the media form would be.
    $copy = $this->fileRepository->copy($source, $directory . '/' . $this->fileName($resource), FileExists::Rename);
    $copy->setOwnerId((int) $this->currentUser()->id());
    $copy->setFilename($this->fileName($resource));
    $copy->setTemporary();
    $copy->save();
    $item->setValue(['target_id' => $copy->id()]);

    $violations = $media->validate();
    if ($violations->count() > 0) {
      $copy->delete();
      foreach ($violations as $violation) {
        $message = $violation->getMessage();
        $this->messenger()->addError($message instanceof MarkupInterface ? $message : (string) $message);
      }
      $form_state->setRebuild();
      return;
    }
    $media->save();
    $this->logger('lws')->notice('Created media item %name from @uri.', [
      '%name' => (string) $media->label(),
      '@uri' => $this->links->uri($storage, $resource),
    ]);
    $this->messenger()->addStatus($this->t('Created the unpublished media item %name.', ['%name' => (string) $media->label()]));
    $form_state->setRedirectUrl($media->toUrl('edit-form'));
  }

  /**
   * The name of the copy: the resource's, with an extension for its type.
   */
  private function fileName(LwsResourceInterface $resource): string {
    $name = $resource->getName();
    if (pathinfo($name, PATHINFO_EXTENSION) === '') {
      $extension = MimeTypes::getDefault()->getExtensions(strtolower(trim(explode(';', (string) $resource->getMediaType())[0])))[0] ?? NULL;
      if ($extension !== NULL) {
        $name .= '.' . $extension;
      }
    }
    return $name;
  }

}
