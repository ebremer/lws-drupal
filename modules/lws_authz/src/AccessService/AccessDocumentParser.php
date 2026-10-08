<?php

declare(strict_types=1);

namespace Drupal\lws_authz\AccessService;

use Drupal\lws\Storage\StorageRef;
use Drupal\lws_authz\Entity\LwsAccessRecordInterface;
use Drupal\lws_authz\Policy\AccessPolicyParser;
use Drupal\lws_authz\Policy\InvalidPolicyException;
use Ebremer\Lws\Json\Json;
use Ebremer\Lws\ResourceType;
use Ebremer\Lws\Vocabulary;

/**
 * Reads access request and access grant documents (LWS Core §11.2, §11.4).
 *
 * A document must be a JSON object with:
 *
 * - "type" including AccessRequest or AccessGrant;
 * - "storage", the URI of the storage it is submitted to;
 * - "access", a non-empty list of valid access policies for that storage;
 * - if it has an "inbox", an http or https URL;
 * - if it has an "@context", one that includes the LWS context, which is
 *   added when there is none.
 *
 * Other members are kept as they are (§11.2: "Other properties MAY be
 * present").
 */
final class AccessDocumentParser {

  public function __construct(
    private readonly AccessPolicyParser $policies,
  ) {}

  /**
   * Reads a document.
   *
   * @param mixed $json
   *   The document, decoded.
   * @param string $kind
   *   The kind: "request" or "grant".
   * @param \Drupal\lws\Storage\StorageRef $storage
   *   The storage it is submitted to.
   *
   * @throws \Drupal\lws_authz\Policy\InvalidPolicyException
   *   When it is not a valid document of that kind for the storage.
   */
  public function parse(mixed $json, string $kind, StorageRef $storage): AccessDocument {
    $document = Json::members($json) ?? throw new InvalidPolicyException('The document must be a JSON object.');
    $term = $kind === LwsAccessRecordInterface::GRANT ? ResourceType::ACCESS_GRANT : ResourceType::ACCESS_REQUEST;

    $context = $document['@context'] ?? [Vocabulary::LWS_CONTEXT];
    $contexts = is_string($context) ? [$context] : $context;
    if (!Json::isList($contexts) || !in_array(Vocabulary::LWS_CONTEXT, $contexts, TRUE)) {
      throw new InvalidPolicyException(sprintf('The "@context" must include %s.', Vocabulary::LWS_CONTEXT));
    }
    $document = ['@context' => $contexts] + $document;

    $type = $document['type'] ?? NULL;
    $types = is_string($type) ? [$type] : $type;
    if (!Json::isList($types) || array_intersect([$term, Vocabulary::LWS_NS . $term], $types) === []) {
      throw new InvalidPolicyException(sprintf('The "type" must include %s.', $term));
    }
    $document['type'] = $types;

    $uri = $document['storage'] ?? NULL;
    if (!is_string($uri) || !in_array($uri, [$storage->uri, rtrim($storage->uri, '/')], TRUE)) {
      throw new InvalidPolicyException(sprintf('The "storage" must be %s.', $storage->uri));
    }

    $inbox = $document['inbox'] ?? NULL;
    $url = is_string($inbox) ? parse_url($inbox) : FALSE;
    $scheme = is_array($url) ? strtolower($url['scheme'] ?? '') : '';
    if ($inbox !== NULL && (!is_array($url) || !in_array($scheme, ['http', 'https'], TRUE) || ($url['host'] ?? '') === '')) {
      throw new InvalidPolicyException('The "inbox" must be an http or https URL.');
    }

    $access = $document['access'] ?? NULL;
    if (!Json::isList($access) || $access === []) {
      throw new InvalidPolicyException('The "access" must be a non-empty list of access policies.');
    }
    $policies = [];
    foreach ($access as $i => $policy) {
      try {
        $policies[] = $this->policies->parse($policy, $storage);
      }
      catch (InvalidPolicyException $e) {
        throw new InvalidPolicyException(sprintf('Access policy %d: %s', $i + 1, $e->getMessage()), 0, $e);
      }
    }
    unset($document['id']);
    return new AccessDocument($kind, $document, $policies, $inbox);
  }

}
