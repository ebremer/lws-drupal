<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lws_authz\Form\TrustedAuthorizationServerForm;
use Drupal\lws_authz\Server\LocalAuthorizationServer;
use Drupal\lws_authz\TrustedAuthorizationServerListBuilder;

/**
 * An authorization server whose access tokens storages accept.
 *
 * Being configuration, trust deploys across environments with the rest of the
 * site's configuration.
 */
#[ConfigEntityType(
  id: 'lws_trusted_as',
  label: new TranslatableMarkup('Trusted authorization server'),
  label_collection: new TranslatableMarkup('LWS authorization servers'),
  label_singular: new TranslatableMarkup('trusted authorization server'),
  label_plural: new TranslatableMarkup('trusted authorization servers'),
  config_prefix: 'trusted_as',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
  ],
  handlers: [
    'list_builder' => TrustedAuthorizationServerListBuilder::class,
    'form' => [
      'add' => TrustedAuthorizationServerForm::class,
      'edit' => TrustedAuthorizationServerForm::class,
      'delete' => EntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/config/services/lws/authorization-servers',
    'add-form' => '/admin/config/services/lws/authorization-servers/add',
    'edit-form' => '/admin/config/services/lws/authorization-servers/{lws_trusted_as}',
    'delete-form' => '/admin/config/services/lws/authorization-servers/{lws_trusted_as}/delete',
  ],
  admin_permission: 'administer lws',
  label_count: [
    'singular' => '@count trusted authorization server',
    'plural' => '@count trusted authorization servers',
  ],
  config_export: [
    'id',
    'label',
    'issuer',
    'jwks',
  ],
)]
class TrustedAuthorizationServer extends ConfigEntityBase implements TrustedAuthorizationServerInterface {

  /**
   * The machine name.
   */
  protected ?string $id = NULL;

  /**
   * The human-readable name.
   */
  protected ?string $label = NULL;

  /**
   * The issuer identifier.
   */
  protected ?string $issuer = NULL;

  /**
   * The pinned JSON Web Key Set, as JSON.
   */
  protected ?string $jwks = NULL;

  /**
   * {@inheritdoc}
   */
  public function getIssuer(): string {
    return (string) $this->issuer;
  }

  /**
   * {@inheritdoc}
   */
  public function getJwks(): ?string {
    return $this->jwks === NULL || $this->jwks === '' ? NULL : $this->jwks;
  }

  /**
   * {@inheritdoc}
   *
   * When the default server is deleted, this site's own becomes the default.
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities): void {
    parent::postDelete($storage, $entities);
    $settings = \Drupal::configFactory()->getEditable('lws_authz.settings');
    if (isset($entities[(string) $settings->get('authorization_server')])) {
      $settings->set('authorization_server', LocalAuthorizationServer::ID)->save();
    }
  }

}
