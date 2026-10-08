<?php

declare(strict_types=1);

namespace Drupal\lws;

/**
 * Vocabulary terms and media types of the LWS 1.0 specifications.
 */
final class Lws {

  /**
   * The LWS vocabulary namespace.
   */
  public const NS = 'https://www.w3.org/ns/lws#';

  /**
   * The LWS JSON-LD context.
   */
  public const CONTEXT = 'https://www.w3.org/ns/lws/v1';

  /**
   * The W3C Controlled Identifiers 1.0 JSON-LD context.
   */
  public const CID_CONTEXT = 'https://www.w3.org/ns/cid/v1';

  /**
   * Link relation from a Storage Resource to its storage (LWS Core §6.1.2).
   */
  public const REL_STORAGE = self::NS . 'storage';

  /**
   * The class of containers.
   */
  public const CONTAINER = self::NS . 'Container';

  /**
   * The class of data resources.
   */
  public const DATA_RESOURCE = self::NS . 'DataResource';

  /**
   * Media type of container representations (LWS Core §12.1).
   */
  public const MEDIA_TYPE_CONTAINER = 'application/lws+json';

  /**
   * Media type of storage descriptions (LWS Core §12.1).
   */
  public const MEDIA_TYPE_STORAGE_DESCRIPTION = 'application/lws+cid';

  /**
   * Media type of problem details (RFC 9457).
   */
  public const MEDIA_TYPE_PROBLEM = 'application/problem+json';

}
