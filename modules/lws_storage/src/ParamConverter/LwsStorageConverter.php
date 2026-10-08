<?php

declare(strict_types=1);

namespace Drupal\lws_storage\ParamConverter;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\ParamConverter\ParamConverterInterface;
use Drupal\lws\Http\LwsHttpException;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Symfony\Component\Routing\Route;

/**
 * Converts the storage slug of an LWS URL to the storage entity.
 *
 * Declared on routes as a parameter of type "lws_storage". An unknown slug is
 * not converted, which answers 404; a blocked storage answers 503.
 */
final class LwsStorageConverter implements ParamConverterInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   *
   * @param mixed $value
   *   The storage slug.
   * @param mixed $definition
   *   The parameter definition.
   * @param string $name
   *   The parameter name.
   * @param array<string, mixed> $defaults
   *   The route defaults.
   */
  public function convert($value, $definition, $name, array $defaults) {
    if (!is_string($value)) {
      return NULL;
    }
    $storages = $this->entityTypeManager->getStorage('lws_storage')->loadByProperties(['slug' => $value]);
    foreach ($storages as $storage) {
      if ($storage instanceof LwsStorageInterface && $storage->getSlug() === $value) {
        if (!$storage->isEnabled()) {
          throw LwsHttpException::serviceUnavailable('This storage is blocked.');
        }
        return $storage;
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function applies($definition, $name, Route $route) {
    return ($definition['type'] ?? NULL) === 'lws_storage';
  }

}
