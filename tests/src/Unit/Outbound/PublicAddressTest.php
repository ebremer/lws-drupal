<?php

declare(strict_types=1);

namespace Drupal\Tests\lws\Unit\Outbound;

use Drupal\lws\Outbound\PublicAddress;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests which addresses outbound requests may reach.
 */
#[CoversClass(PublicAddress::class)]
#[Group('lws')]
final class PublicAddressTest extends UnitTestCase {

  /**
   * Addresses, and whether they are public.
   *
   * @return array<string, array{string, bool}>
   *   Address, whether it is globally routable.
   */
  public static function addresses(): array {
    return [
      'public IPv4' => ['93.184.215.14', TRUE],
      'public IPv4, next to private' => ['172.32.0.1', TRUE],
      'public IPv6' => ['2606:2800:21f:cb07:6820:80da:af6b:8b2c', TRUE],
      'loopback' => ['127.0.0.1', FALSE],
      'loopback, elsewhere in 127/8' => ['127.1.2.3', FALSE],
      'this network' => ['0.0.0.0', FALSE],
      'private 10/8' => ['10.1.2.3', FALSE],
      'private 172.16/12' => ['172.31.255.255', FALSE],
      'private 192.168/16' => ['192.168.1.1', FALSE],
      'shared address space' => ['100.64.0.1', FALSE],
      'link-local, cloud metadata' => ['169.254.169.254', FALSE],
      'documentation' => ['203.0.113.7', FALSE],
      'benchmarking' => ['198.19.0.1', FALSE],
      'multicast' => ['239.1.1.1', FALSE],
      'broadcast' => ['255.255.255.255', FALSE],
      'IPv6 loopback' => ['::1', FALSE],
      'IPv6 unspecified' => ['::', FALSE],
      'IPv6 unique local' => ['fd00::1', FALSE],
      'IPv6 link-local' => ['fe80::1', FALSE],
      'IPv6 multicast' => ['ff02::1', FALSE],
      'IPv6 documentation' => ['2001:db8::1', FALSE],
      'Teredo' => ['2001:0:4136:e378:8000:63bf:3fff:fdd2', FALSE],
      'IPv4-mapped loopback' => ['::ffff:127.0.0.1', FALSE],
      'IPv4-mapped public' => ['::ffff:93.184.215.14', TRUE],
      'NAT64 of a private address' => ['64:ff9b::a00:1', FALSE],
      'NAT64 of a public address' => ['64:ff9b::5db8:d70e', TRUE],
      '6to4 of a private address' => ['2002:c0a8:101::1', FALSE],
      '6to4 of a public address' => ['2002:5db8:d70e::1', TRUE],
      'not an address' => ['example.com', FALSE],
    ];
  }

  /**
   * Tests the ranges.
   */
  #[DataProvider('addresses')]
  public function testIsPublic(string $address, bool $public): void {
    $this->assertSame($public, PublicAddress::isPublic($address));
  }

}
