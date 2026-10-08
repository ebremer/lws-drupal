<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Cid;

use Ebremer\Lws\Auth\DidKey;
use Ebremer\Lws\Auth\VerificationKey;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

/**
 * Finds the verification methods of a controlled identifier document.
 *
 * Retrieval follows CID 1.0 §3.3 for the authentication relationship:
 *
 * - only methods that relationship names count, embedded in it or referenced
 *   from it and defined elsewhere in the same document; a key listed under
 *   verificationMethod alone is not one the subject authenticates with;
 * - a method must be controlled by the subject and live in its document;
 * - a JsonWebKey carries a publicKeyJwk with no private members, a Multikey a
 *   publicKeyMultibase. Their predecessors, JsonWebKey2020 and
 *   Ed25519VerificationKey2020, carry the same members and are still common
 *   in DID documents, so they are read the same way;
 * - a method with an unreadable revoked or expires time is not used.
 *
 * Methods with keys this site cannot verify with are skipped.
 */
final class VerificationMethods {

  /**
   * JWK members of the private information class (RFC 7517, RFC 7518).
   */
  private const PRIVATE_MEMBERS = ['d', 'p', 'q', 'dp', 'dq', 'qi', 'oth', 'k'];

  /**
   * An XML Schema dateTimeStamp, which CID 1.0 uses for revoked and expires.
   */
  private const DATE_TIME_STAMP = '/^-?\d{4,}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/';

  /**
   * The methods a subject's document names for authentication.
   *
   * @param array<array-key, mixed> $document
   *   The subject's document; its "id" must be the subject.
   * @param string $subject
   *   The subject identifier.
   *
   * @return list<\Drupal\lws_authz\Cid\VerificationMethod>
   *   The usable methods, possibly none.
   */
  public static function authentication(array $document, string $subject): array {
    $entries = $document['authentication'] ?? [];
    if (!is_array($entries) || !array_is_list($entries)) {
      $entries = [$entries];
    }
    $methods = [];
    foreach ($entries as $entry) {
      $method = NULL;
      if (is_string($entry)) {
        $reference = self::resolve($entry, $subject);
        $method = $reference === NULL ? NULL : self::find($document, $reference, $subject);
      }
      elseif (is_array($entry)) {
        $method = $entry;
      }
      $method = $method === NULL ? NULL : self::method($method, $subject);
      if ($method !== NULL && !isset($methods[$method->id])) {
        $methods[$method->id] = $method;
      }
    }
    return array_values($methods);
  }

  /**
   * The method a JWT's "kid" names.
   *
   * By the method's identifier first (CID 1.0 §3.3 retrieves by it, and it is
   * the usual "kid" for a DID), then by its JSON Web Key's "kid", then by the
   * fragment of its identifier, with or without a leading "#". There is no
   * fallback to the only key: the credential says which key signed it.
   *
   * @param list<\Drupal\lws_authz\Cid\VerificationMethod> $methods
   *   The candidate methods.
   * @param string $kid
   *   The "kid".
   */
  public static function select(array $methods, string $kid): ?VerificationMethod {
    if ($kid === '') {
      return NULL;
    }
    foreach ($methods as $method) {
      if ($method->id === $kid) {
        return $method;
      }
    }
    foreach ($methods as $method) {
      if ($method->keyId === $kid) {
        return $method;
      }
    }
    $wanted = str_starts_with($kid, '#') ? substr($kid, 1) : $kid;
    foreach ($methods as $method) {
      $hash = strpos($method->id, '#');
      $fragment = $hash === FALSE ? NULL : substr($method->id, $hash + 1);
      if ($fragment !== NULL && ($fragment === $wanted || rawurldecode($fragment) === $wanted)) {
        return $method;
      }
    }
    return NULL;
  }

  /**
   * Resolves a reference in a document against the document's identifier.
   *
   * An absolute URI or DID URL stays as it is; a fragment is appended to the
   * identifier; any other relative reference is resolved by RFC 3986 against
   * a hierarchical identifier, and is NULL against a DID.
   */
  public static function resolve(string $reference, string $base): ?string {
    if ($reference === '') {
      return NULL;
    }
    if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $reference) === 1) {
      return $reference;
    }
    $document = self::documentOf($base);
    if (str_starts_with($reference, '#')) {
      return $document . $reference;
    }
    if (!str_contains($document, '://')) {
      return NULL;
    }
    try {
      return (string) UriResolver::resolve(new Uri($document), new Uri($reference));
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
  }

  /**
   * A verification method object, if the subject may authenticate with it.
   *
   * @param array<array-key, mixed> $method
   *   The object.
   * @param string $subject
   *   The subject identifier, which is the document's.
   */
  private static function method(array $method, string $subject): ?VerificationMethod {
    $id = is_string($method['id'] ?? NULL) ? self::resolve($method['id'], $subject) : NULL;
    $controller = is_string($method['controller'] ?? NULL) ? self::resolve($method['controller'], $subject) : NULL;
    if ($id === NULL || $controller !== $subject || self::documentOf($id) !== self::documentOf($subject)) {
      return NULL;
    }
    try {
      $revoked = self::time($method['revoked'] ?? NULL);
      $expires = self::time($method['expires'] ?? NULL);
      $type = $method['type'] ?? NULL;
      if ($type === 'JsonWebKey' || $type === 'JsonWebKey2020') {
        $jwk = $method['publicKeyJwk'] ?? NULL;
        if (!is_array($jwk) || array_is_list($jwk) || array_intersect(array_keys($jwk), self::PRIVATE_MEMBERS) !== []) {
          return NULL;
        }
        $key = VerificationKey::fromJwk($jwk);
        if (isset($jwk['alg']) && $jwk['alg'] !== $key->algorithm()) {
          return NULL;
        }
        return new VerificationMethod($id, $key, is_string($jwk['kid'] ?? NULL) ? $jwk['kid'] : NULL, $revoked, $expires);
      }
      if ($type === 'Multikey' || $type === 'Ed25519VerificationKey2020') {
        $multibase = $method['publicKeyMultibase'] ?? NULL;
        if (!is_string($multibase) || !str_starts_with($multibase, 'z')) {
          return NULL;
        }
        // A did:key's method-specific identifier is the key's multibase.
        return new VerificationMethod($id, DidKey::publicKey('did:key:' . $multibase), NULL, $revoked, $expires);
      }
    }
    catch (\InvalidArgumentException) {
      // An unusable key, or an unreadable time.
    }
    return NULL;
  }

  /**
   * The first object in a document whose "id" resolves to a reference.
   *
   * The verificationMethod property is searched before the rest.
   *
   * @param array<array-key, mixed> $document
   *   The document.
   * @param string $reference
   *   The absolute method identifier.
   * @param string $base
   *   The document identifier.
   *
   * @return array<array-key, mixed>|null
   *   The object.
   */
  private static function find(array $document, string $reference, string $base): ?array {
    $listed = $document['verificationMethod'] ?? NULL;
    if (is_array($listed)) {
      $found = self::search($listed, $reference, $base);
      if ($found !== NULL) {
        return $found;
      }
    }
    return self::search($document, $reference, $base);
  }

  /**
   * Searches a JSON value depth-first for an object with an identifier.
   *
   * @param array<array-key, mixed> $value
   *   The value.
   * @param string $reference
   *   The absolute identifier.
   * @param string $base
   *   The document identifier.
   *
   * @return array<array-key, mixed>|null
   *   The object.
   */
  private static function search(array $value, string $reference, string $base): ?array {
    if (!array_is_list($value) && is_string($value['id'] ?? NULL) && self::resolve($value['id'], $base) === $reference) {
      return $value;
    }
    foreach ($value as $child) {
      if (is_array($child)) {
        $found = self::search($child, $reference, $base);
        if ($found !== NULL) {
          return $found;
        }
      }
    }
    return NULL;
  }

  /**
   * A dateTimeStamp as a Unix time; NULL if absent.
   *
   * @throws \InvalidArgumentException
   *   When it is present but unreadable: an unreadable revocation time is no
   *   evidence that a key was never revoked.
   */
  private static function time(mixed $value): ?int {
    if ($value === NULL) {
      return NULL;
    }
    if (!is_string($value) || preg_match(self::DATE_TIME_STAMP, $value) !== 1) {
      throw new \InvalidArgumentException('Not a dateTimeStamp.');
    }
    try {
      return (new \DateTimeImmutable($value))->getTimestamp();
    }
    catch (\Exception $e) {
      throw new \InvalidArgumentException('Not a dateTimeStamp.', 0, $e);
    }
  }

  /**
   * An identifier without its fragment.
   */
  private static function documentOf(string $identifier): string {
    $hash = strpos($identifier, '#');
    return $hash === FALSE ? $identifier : substr($identifier, 0, $hash);
  }

}
