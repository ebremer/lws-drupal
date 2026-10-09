<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Cid;

/**
 * The controlled identifier documents this site serves itself.
 *
 * DocumentResolver reads them here rather than over HTTP: a request from the
 * site to itself would pass the outbound guard only on a public address, and
 * would hold a second PHP worker while the first waits for it.
 *
 * Implemented by lws_identity as the service with this interface's name.
 */
interface LocalDocumentsInterface {

  /**
   * Whether this site serves the documents at a URL, existing or not.
   *
   * @param string $url
   *   An absolute URL without a fragment.
   */
  public function serves(string $url): bool;

  /**
   * The document at a URL this site serves, as a JSON object.
   *
   * @param string $url
   *   An absolute URL without a fragment, for which serves() is TRUE.
   *
   * @return array<string, mixed>|null
   *   The document, or NULL if there is none at that URL now.
   */
  public function document(string $url): ?array;

}
