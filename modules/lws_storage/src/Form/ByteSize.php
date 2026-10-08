<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Form;

use Drupal\Component\Utility\Bytes;

/**
 * Sizes in forms: "10 GB" in, bytes out, and back exactly.
 *
 * Unlike ByteSizeMarkup, the text never rounds and is never translated, so a
 * form that shows a size and saves it again leaves it as it was.
 */
final class ByteSize {

  private const UNITS = ['TB' => 4, 'GB' => 3, 'MB' => 2, 'KB' => 1];

  /**
   * The size as text: in the largest unit that is exact, else in bytes.
   */
  public static function format(int $bytes): string {
    foreach (self::UNITS as $unit => $power) {
      $size = Bytes::KILOBYTE ** $power;
      if ($bytes >= $size && $bytes % $size === 0) {
        return sprintf('%d %s', intdiv($bytes, $size), $unit);
      }
    }
    return (string) $bytes;
  }

  /**
   * The bytes of a size given as text, or NULL if it is not one.
   */
  public static function parse(string $text): ?int {
    $text = trim($text);
    if ($text === '' || !Bytes::validate($text)) {
      return NULL;
    }
    $bytes = Bytes::toNumber($text);
    return $bytes >= 1 && $bytes <= PHP_INT_MAX ? (int) $bytes : NULL;
  }

}
