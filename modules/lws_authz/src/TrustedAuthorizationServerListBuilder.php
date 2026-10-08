<?php

declare(strict_types=1);

namespace Drupal\lws_authz;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\lws_authz\Entity\TrustedAuthorizationServerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists the trusted authorization servers.
 */
final class TrustedAuthorizationServerListBuilder extends ConfigEntityListBuilder {

  /**
   * The config factory, for the default server.
   */
  protected ConfigFactoryInterface $configFactory;

  public function __construct(EntityTypeInterface $entity_type, EntityStorageInterface $storage, ConfigFactoryInterface $config_factory) {
    parent::__construct($entity_type, $storage);
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new self(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The header row.
   */
  public function buildHeader(): array {
    return [
      'label' => $this->t('Name'),
      'issuer' => $this->t('Issuer'),
      'keys' => $this->t('Keys'),
      'default' => $this->t('Default'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The row.
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof TrustedAuthorizationServerInterface);
    $default = $this->configFactory->get('lws_authz.settings')->get('authorization_server');
    return [
      'label' => $entity->label(),
      'issuer' => $entity->getIssuer(),
      'keys' => $entity->getJwks() === NULL ? $this->t('From its metadata') : $this->t('Pinned'),
      'default' => $entity->id() === $default ? $this->t('Yes') : '',
    ] + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The render array.
   */
  public function render(): array {
    $build = parent::render();
    $build['table']['#empty'] = $this->t("No external authorization servers are trusted: storages accept the access tokens of this site's own.");
    return $build;
  }

}
