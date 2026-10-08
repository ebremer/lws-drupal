<?php

declare(strict_types=1);

namespace Drupal\lws_storage_test\StreamWrapper;

use Drupal\Core\StreamWrapper\PrivateStream;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The private file system, through streams that cannot seek ("noseek://").
 *
 * Like a remote stream wrapper whose content arrives as it is read.
 */
final class NoSeekStream extends PrivateStream {

  /**
   * {@inheritdoc}
   */
  public function getName(): TranslatableMarkup {
    return new TranslatableMarkup('Private files that cannot seek');
  }

  /**
   * {@inheritdoc}
   */
  // phpcs:ignore Drupal.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
  public function stream_seek($offset, $whence = SEEK_SET): bool {
    return FALSE;
  }

}
