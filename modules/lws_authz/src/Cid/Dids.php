<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Cid;

use Ebremer\Lws\Auth\DidKey;

/**
 * Decentralized Identifiers as subject identifiers (DID 1.1).
 *
 * The self-signed controlled identifier suite works with DID subjects because
 * a DID document extends a controlled identifier document. It mandates no DID
 * method. This site resolves the two that need no ledger and no third-party
 * resolver: did:key, whose document is derived from the key the identifier
 * embeds, and did:web, whose document is fetched over HTTPS from the domain
 * it names. Every other method is refused.
 */
final class Dids {

  /**
   * The DID methods this site resolves.
   */
  public const METHODS = ['key', 'web'];

  /**
   * A DID (DID 1.1 §3.1): never a DID URL, so no "/", "?" or "#".
   */
  private const DID = '/^did:([a-z0-9]+):((?:(?:[A-Za-z0-9._-]|%[0-9A-Fa-f]{2})*:)*(?:[A-Za-z0-9._-]|%[0-9A-Fa-f]{2})+)$/';

  /**
   * A DNS label.
   */
  private const LABEL = '/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/';

  /**
   * The method name of a DID, or NULL if it is not a syntactically valid DID.
   */
  public static function method(string $did): ?string {
    return preg_match(self::DID, $did, $matches) === 1 ? $matches[1] : NULL;
  }

  /**
   * The DID document a did:key expands to.
   *
   * It has the method's default Multikey form: one verification method,
   * "{did}#{multibase}", controlled by the DID and named by the
   * authentication and assertionMethod relationships.
   *
   * @return array<string, mixed>
   *   The document.
   *
   * @throws \InvalidArgumentException
   *   When it is not a did:key of a P-256 or Ed25519 key.
   */
  public static function didKeyDocument(string $did): array {
    if (self::method($did) !== 'key') {
      throw new \InvalidArgumentException('Not a did:key.');
    }
    // Decoding checks the key type and the encoding.
    DidKey::publicKey($did);
    $multibase = substr($did, strlen('did:key:'));
    $methodId = $did . '#' . $multibase;
    return [
      '@context' => ['https://www.w3.org/ns/did/v1.1'],
      'id' => $did,
      'verificationMethod' => [
        [
          'id' => $methodId,
          'type' => 'Multikey',
          'controller' => $did,
          'publicKeyMultibase' => $multibase,
        ],
      ],
      'authentication' => [$methodId],
      'assertionMethod' => [$methodId],
    ];
  }

  /**
   * The HTTPS URL of a did:web's DID document.
   *
   * The method-specific identifier is a domain name, never an IP address,
   * with a port only as a percent-encoded colon. So did:web:example.com has
   * its document at https://example.com/.well-known/did.json, and
   * did:web:example.com%3A3000:alice at
   * https://example.com:3000/alice/did.json.
   *
   * @throws \InvalidArgumentException
   *   When it is not a valid did:web.
   */
  public static function didWebUrl(string $did): string {
    if (self::method($did) !== 'web') {
      throw new \InvalidArgumentException('Not a did:web.');
    }
    $parts = explode(':', substr($did, strlen('did:web:')));
    $host = array_shift($parts);
    $port = NULL;
    $colon = stripos($host, '%3a');
    if ($colon !== FALSE) {
      $port = substr($host, $colon + 3);
      $host = substr($host, 0, $colon);
      if (preg_match('/^[0-9]{1,5}$/', $port) !== 1 || (int) $port < 1 || (int) $port > 65535) {
        throw new \InvalidArgumentException('The did:web port is not a number from 1 to 65535.');
      }
    }
    if (!self::isDomainName($host)) {
      throw new \InvalidArgumentException('A did:web names a domain.');
    }
    $url = 'https://' . strtolower($host) . ($port === NULL ? '' : ':' . (int) $port);
    if ($parts === []) {
      return $url . '/.well-known/did.json';
    }
    foreach ($parts as $part) {
      if ($part === '') {
        throw new \InvalidArgumentException('The did:web path has an empty segment.');
      }
      $url .= '/' . $part;
    }
    return $url . '/did.json';
  }

  /**
   * Whether a host is a domain name: labels whose last is not all digits.
   */
  private static function isDomainName(string $host): bool {
    if ($host === '' || strlen($host) > 253) {
      return FALSE;
    }
    $labels = explode('.', $host);
    foreach ($labels as $label) {
      if (preg_match(self::LABEL, $label) !== 1) {
        return FALSE;
      }
    }
    return preg_match('/^[0-9]+$/', (string) end($labels)) !== 1;
  }

}
