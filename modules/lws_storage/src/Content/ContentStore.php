<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Content;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\lws\Http\LwsHttpException;

/**
 * Writes the content of data resources to the file system (DESIGN.md §5.3).
 *
 * Each write goes to a new URI, {scheme}://lws/{storage}/{aa}/{bb}/{uuid}.{n},
 * made of UUIDs and a random suffix, never of client input. A reader of the
 * previous version is never disturbed, and two concurrent writes never share
 * a file. The body is streamed and hashed as it goes, never held in memory.
 */
final class ContentStore {

  /**
   * The directory below the scheme root that holds all LWS content.
   */
  public const DIRECTORY = 'lws';

  /**
   * The size of the chunks the body is copied in, in bytes.
   */
  private const CHUNK = 1048576;

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly StreamWrapperManagerInterface $streamWrappers,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * The scheme of the stream wrapper that holds content.
   */
  public function scheme(): string {
    return (string) ($this->configFactory->get('lws_storage.settings')->get('scheme') ?: 'private');
  }

  /**
   * Whether that stream wrapper is available.
   */
  public function isAvailable(): bool {
    return $this->streamWrappers->isValidScheme($this->scheme());
  }

  /**
   * Whether a URI is in the LWS content directory.
   */
  public function owns(string $uri): bool {
    $target = $this->streamWrappers->getTarget($uri);
    return is_string($target) && str_starts_with($target, self::DIRECTORY . '/');
  }

  /**
   * The largest body accepted, in bytes; 0 for no limit of the module's own.
   */
  public function maxBytes(): int {
    return (int) $this->configFactory->get('lws_storage.settings')->get('max_upload_bytes');
  }

  /**
   * Writes a body to a new file.
   *
   * @param resource $body
   *   The body, as a readable stream.
   * @param string $storageUuid
   *   The UUID of the storage.
   * @param string $resourceUuid
   *   The UUID of the resource.
   *
   * @throws \Drupal\lws\Http\LwsHttpException
   *   413 when the body is too large; 503 when the content cannot be stored.
   */
  public function write($body, string $storageUuid, string $resourceUuid): StoredContent {
    if (!$this->isAvailable()) {
      throw LwsHttpException::serviceUnavailable('The file system that holds content is not configured.');
    }
    $directory = sprintf('%s://%s/%s/%s/%s', $this->scheme(), self::DIRECTORY, $storageUuid, substr($resourceUuid, 0, 2), substr($resourceUuid, 2, 2));
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw LwsHttpException::serviceUnavailable('Content cannot be stored.');
    }
    $uri = $directory . '/' . $resourceUuid . '.' . bin2hex(random_bytes(6));
    $out = @fopen($uri, 'xb');
    if ($out === FALSE) {
      throw LwsHttpException::serviceUnavailable('Content cannot be stored.');
    }

    $limit = $this->maxBytes();
    $hash = hash_init('sha256');
    $size = 0;
    try {
      while (!feof($body)) {
        $chunk = fread($body, self::CHUNK);
        if ($chunk === FALSE) {
          throw LwsHttpException::badRequest('The request body could not be read.');
        }
        $size += strlen($chunk);
        if ($limit > 0 && $size > $limit) {
          throw LwsHttpException::contentTooLarge($limit);
        }
        hash_update($hash, $chunk);
        if ($chunk !== '' && fwrite($out, $chunk) !== strlen($chunk)) {
          throw LwsHttpException::serviceUnavailable('Content cannot be stored.');
        }
      }
    }
    catch (\Throwable $e) {
      fclose($out);
      $this->delete($uri);
      throw $e;
    }
    fclose($out);
    return new StoredContent($uri, $size, rtrim(strtr(base64_encode(hash_final($hash, TRUE)), '+/', '-_'), '='));
  }

  /**
   * Deletes the bytes at a URI, if there are any.
   */
  public function delete(string $uri): void {
    if (file_exists($uri)) {
      $this->fileSystem->delete($uri);
    }
  }

}
