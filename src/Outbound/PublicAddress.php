<?php

declare(strict_types=1);

namespace Drupal\lws\Outbound;

/**
 * Tells globally routable IP addresses from all others.
 *
 * The ranges are those of the IANA IPv4 and IPv6 special-purpose address
 * registries, plus multicast. Addresses that embed an IPv4 address (mapped,
 * NAT64, 6to4) are judged by the embedded address.
 */
final class PublicAddress {

  /**
   * IPv4 ranges that are not globally routable.
   */
  private const IPV4_BLOCKED = [
    '0.0.0.0/8',
    '10.0.0.0/8',
    '100.64.0.0/10',
    '127.0.0.0/8',
    '169.254.0.0/16',
    '172.16.0.0/12',
    '192.0.0.0/24',
    '192.0.2.0/24',
    '192.88.99.0/24',
    '192.168.0.0/16',
    '198.18.0.0/15',
    '198.51.100.0/24',
    '203.0.113.0/24',
    '224.0.0.0/4',
    '240.0.0.0/4',
  ];

  /**
   * IPv6 ranges that are not globally routable.
   */
  private const IPV6_BLOCKED = [
    // Unspecified, loopback and the deprecated IPv4-compatible addresses.
    '::/96',
    '64:ff9b:1::/48',
    '100::/64',
    '2001::/23',
    '2001:db8::/32',
    '3fff::/20',
    '5f00::/16',
    'fc00::/7',
    'fe80::/10',
    'fec0::/10',
    'ff00::/8',
  ];

  /**
   * Whether an address is globally routable.
   *
   * @param string $address
   *   An IPv4 or IPv6 address, without brackets.
   */
  public static function isPublic(string $address): bool {
    $packed = @inet_pton($address);
    if ($packed === FALSE) {
      return FALSE;
    }
    if (strlen($packed) === 4) {
      return !self::inAny($packed, self::IPV4_BLOCKED);
    }
    $embedded = self::embeddedIpv4($packed);
    if ($embedded !== NULL) {
      return !self::inAny($embedded, self::IPV4_BLOCKED);
    }
    return !self::inAny($packed, self::IPV6_BLOCKED);
  }

  /**
   * The IPv4 address an IPv6 address stands for, if any.
   */
  private static function embeddedIpv4(string $packed): ?string {
    // IPv4-mapped, ::ffff:0:0/96.
    if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
      return substr($packed, 12);
    }
    // NAT64, 64:ff9b::/96.
    if (str_starts_with($packed, "\x00\x64\xff\x9b" . str_repeat("\0", 8))) {
      return substr($packed, 12);
    }
    // 6to4, 2002::/16.
    if (str_starts_with($packed, "\x20\x02")) {
      return substr($packed, 2, 4);
    }
    return NULL;
  }

  /**
   * Whether a packed address is in any of the ranges.
   *
   * @param string $packed
   *   The address, as inet_pton() returns it.
   * @param list<string> $ranges
   *   Ranges in CIDR notation, of the same address family.
   */
  private static function inAny(string $packed, array $ranges): bool {
    foreach ($ranges as $range) {
      [$network, $bits] = explode('/', $range);
      $prefix = (string) inet_pton($network);
      $bytes = intdiv((int) $bits, 8);
      $rest = (int) $bits % 8;
      if (substr($packed, 0, $bytes) !== substr($prefix, 0, $bytes)) {
        continue;
      }
      if ($rest === 0) {
        return TRUE;
      }
      $mask = (0xff << (8 - $rest)) & 0xff;
      if ((ord($packed[$bytes]) & $mask) === (ord($prefix[$bytes]) & $mask)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
