<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Http;

use Drupal\lws\Http\LwsHttpException;
use Drupal\lws\Http\RequestBody;
use Ebremer\Lws\Json\Json;
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
   * The most operations one patch may have.
   */
  public const MAX_OPERATIONS = 1000;

  /**
   * The most "copy" operations one patch may have.
   *
   * Each may double the document, so after each the document's size is
   * checked, which is not free.
   */
  public const MAX_COPIES = 32;

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
    $body = RequestBody::read($request, self::MAX_BYTES);
    try {
      $patch = JsonPatch::fromJson($body);
    }
    catch (\InvalidArgumentException $e) {
      throw LwsHttpException::badRequest($e->getMessage());
    }
    $copies = count(array_filter($patch->operations(), static fn (array $operation): bool => $operation['op'] === 'copy'));
    if (count($patch) > self::MAX_OPERATIONS || $copies > self::MAX_COPIES) {
      throw LwsHttpException::unprocessable(sprintf('A patch may have at most %d operations, %d of them "copy".', self::MAX_OPERATIONS, self::MAX_COPIES));
    }
    return $patch;
  }

  /**
   * Applies a patch, refusing a document that grows too large on the way.
   *
   * The operations apply one after another, as RFC 6902 §3 has them; a
   * "copy" is the only one that can make the document much larger than the
   * patch, so the size is checked after each.
   *
   * @throws \Ebremer\Lws\Json\JsonPatchException
   *   When an operation fails.
   * @throws \Drupal\lws\Http\LwsHttpException
   *   422 when the document grows beyond the limit.
   */
  public static function apply(JsonPatch $patch, mixed $document, int $maxBytes): mixed {
    foreach ($patch->operations() as $operation) {
      $document = (new JsonPatch([$operation]))->apply($document);
      if ($operation['op'] === 'copy' && strlen(Json::encode($document)) > $maxBytes) {
        throw LwsHttpException::unprocessable(sprintf('The patched document would be larger than %d bytes.', $maxBytes));
      }
    }
    return $document;
  }

}
