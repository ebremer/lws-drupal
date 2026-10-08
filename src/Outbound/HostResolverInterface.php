<?php

declare(strict_types=1);

namespace Drupal\lws\Outbound;

/**
 * Resolves host names to IP addresses.
 */
interface HostResolverInterface {

  /**
   * The IPv4 and IPv6 addresses of a host name.
   *
   * @return list<string>
   *   The addresses, without brackets; empty if the name does not resolve.
   */
  public function resolve(string $host): array;

}
