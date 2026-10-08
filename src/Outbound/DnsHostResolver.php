<?php

declare(strict_types=1);

namespace Drupal\lws\Outbound;

/**
 * Resolves host names through the system's DNS resolver.
 */
final class DnsHostResolver implements HostResolverInterface {

  /**
   * {@inheritdoc}
   */
  public function resolve(string $host): array {
    $addresses = [];
    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    foreach ($records ?: [] as $record) {
      $address = $record['ip'] ?? $record['ipv6'] ?? NULL;
      if (is_string($address)) {
        $addresses[] = $address;
      }
    }
    // Names that only the hosts file knows, such as "localhost".
    if ($addresses === []) {
      $addresses = gethostbynamel($host) ?: [];
    }
    return array_values(array_unique($addresses));
  }

}
