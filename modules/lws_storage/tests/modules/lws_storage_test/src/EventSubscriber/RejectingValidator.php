<?php

declare(strict_types=1);

namespace Drupal\lws_storage_test\EventSubscriber;

use Drupal\file\Validation\FileValidationEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Validator\ConstraintViolation;

/**
 * A file validator, as a virus scanner would be, that rejects 31 bytes.
 */
final class RejectingValidator implements EventSubscriberInterface {

  /**
   * The size of the content it rejects.
   */
  public const REJECTED_SIZE = 31;

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [FileValidationEvent::class => 'onValidate'];
  }

  /**
   * Rejects files of the rejected size.
   */
  public function onValidate(FileValidationEvent $event): void {
    if ((int) $event->file->getSize() === self::REJECTED_SIZE) {
      $event->violations->add(new ConstraintViolation('The test validator rejected the file.', NULL, [], $event->file, '', NULL));
    }
  }

}
