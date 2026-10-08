<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Http;

use Drupal\lws\Http\LwsHttpException;
use Ebremer\Lws\Json\JsonPatch;
use Ebremer\Lws\MediaType;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON Patch requests (RFC 6902), the LWS baseline patch format (§9.4).
 */
final class JsonPatches {

  /**
   * The largest JSON Patch accepted, in bytes.
   */
  public const MAX_BYTES = 1048576;

  /**
   * The patch formats resources and linksets accept, for Accept-Patch.
   */
  public const ACCEPTED = [MediaType::JSON_PATCH];

  /**
   * Whether a media type is JSON: application/json or a "+json" type.
   */
  public static function isJson(string $mediaType): bool {
    $type = strtolower(trim(explode(';', $mediaType)[0]));
    return $type === MediaType::JSON || str_ends_with($type, '+json');
  }

  /**
   * The JSON Patch a request carries.
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   415 for another patch format, 413 for a large one, 400 for a malformed
   *   one.
   */
  public static function fromRequest(Request $request): JsonPatch {
    $type = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));
    if ($type !== MediaType::JSON_PATCH) {
      throw LwsHttpException::unsupportedMediaType('Send a JSON Patch, as application/json-patch+json.', self::ACCEPTED);
    }
    $body = (string) $request->getContent();
    if (strlen($body) > self::MAX_BYTES) {
      throw LwsHttpException::contentTooLarge(self::MAX_BYTES);
    }
    try {
      return JsonPatch::fromJson($body);
    }
    catch (\InvalidArgumentException $e) {
      throw LwsHttpException::badRequest($e->getMessage());
    }
  }

}
