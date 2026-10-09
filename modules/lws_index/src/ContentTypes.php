<?php

declare(strict_types=1);

namespace Drupal\lws_index;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lws_storage\Entity\LwsResourceInterface;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\ResourceLinks;
use pietercolpaert\hardf\TriGParser;
use Psr\Log\LoggerInterface;

/**
 * The types a data resource's RDF content says it has (lws10-index §4).
 *
 * "Servers MAY additionally derive types from the resource representation
 * itself when they are able to parse it", and those types "MUST be treated
 * identically" to the ones of Link headers: the index holds both alike. With
 * lws_index.settings:content_types.enabled, content in Turtle or N-Triples no
 * larger than content_types.max_bytes is read. Its types are the IRI objects
 * of rdf:type statements whose subject is the resource itself, "<>" in
 * Turtle. Content that does not parse states no type; it is stored all the
 * same.
 *
 * The content is read while the write that changed it is saved, so the index
 * follows each create, replace and delete at once and rolls back with it; the
 * size limit bounds the cost. Remote JSON-LD contexts are never fetched, as
 * JSON-LD is not read.
 */
final class ContentTypes {

  /**
   * The predicate of type statements.
   */
  public const RDF_TYPE = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';

  /**
   * The media types whose content is read, and the parser's format for each.
   */
  public const FORMATS = [
    'text/turtle' => 'turtle',
    'application/n-triples' => 'n-triples',
  ];

  /**
   * The most types one resource's content adds to the index.
   */
  public const MAX_TYPES = 64;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ResourceLinks $links,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Whether content is read for types, and up to how many bytes.
   *
   * @return int<0, max>|null
   *   The largest content read; NULL when none is.
   */
  public function limit(): ?int {
    $settings = $this->configFactory->get('lws_index.settings');
    return $settings->get('content_types.enabled') ? max(0, (int) $settings->get('content_types.max_bytes')) : NULL;
  }

  /**
   * The types a resource's content states, in the order it states them.
   *
   * @return list<string>
   *   The types, IRIs; none when the content is not read.
   */
  public function of(LwsResourceInterface $resource): array {
    $limit = $this->limit();
    $format = self::FORMATS[strtolower(trim(explode(';', (string) $resource->getMediaType())[0]))] ?? NULL;
    $file = $resource->isContainer() ? NULL : $resource->getContentFile();
    if ($limit === NULL || $format === NULL || $file === NULL || (int) $file->getSize() > $limit) {
      return [];
    }
    $bytes = @file_get_contents((string) $file->getFileUri(), FALSE, NULL, 0, $limit + 1);
    $storage = $this->entityTypeManager->getStorage('lws_storage')->load($resource->getLwsStorageId());
    if ($bytes === FALSE || strlen($bytes) > $limit || !$storage instanceof LwsStorageInterface) {
      return [];
    }
    $uri = $this->links->uri($storage, $resource);
    try {
      $triples = (new TriGParser(['format' => $format, 'documentIRI' => $uri]))->parse($bytes);
    }
    catch (\Throwable $e) {
      $this->logger->info('The content of @uri states no types: it is not valid @format (@message).', [
        '@uri' => $uri,
        '@format' => $format,
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
    $types = [];
    foreach (is_array($triples) ? $triples : [] as $triple) {
      $object = $triple['object'] ?? NULL;
      // Literals are quoted and blank nodes start with "_:"; a type is an IRI.
      if (($triple['subject'] ?? NULL) === $uri && ($triple['predicate'] ?? NULL) === self::RDF_TYPE && is_string($object) && !str_starts_with($object, '"') && !str_starts_with($object, '_:')) {
        $types[$object] = TRUE;
        if (count($types) >= self::MAX_TYPES) {
          break;
        }
      }
    }
    return array_keys($types);
  }

}
