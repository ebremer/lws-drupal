<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Http;

use Ebremer\Lws\Http\LinkHeader;
use Ebremer\Lws\LinkRelation;
use Ebremer\Lws\Prefer;
use Ebremer\Lws\ResourceType;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the headers of a write request say about the resource it writes.
 */
final class WriteRequests {

  /**
   * The request's Link headers.
   *
   * @return list<\Ebremer\Lws\Http\Link>
   *   The links.
   */
  public static function links(Request $request): array {
    return LinkHeader::parse(array_filter($request->headers->all('link'), 'is_string'));
  }

  /**
   * Whether a create's links make the new resource a container.
   *
   * @param list<\Ebremer\Lws\Http\Link> $links
   *   The request's links.
   */
  public static function createsContainer(array $links): bool {
    foreach ($links as $link) {
      if ($link->rel === LinkRelation::TYPE && $link->href === ResourceType::CONTAINER && !isset($link->params['anchor'])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether a request asks for its Link headers to update the linkset too.
   */
  public static function prefersSetLinkset(Request $request): bool {
    foreach ($request->headers->all('prefer') as $value) {
      foreach (preg_split('/\s*[,;]\s*/', strtolower((string) $value)) ?: [] as $preference) {
        if ($preference === Prefer::SET_LINKSET) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

}
