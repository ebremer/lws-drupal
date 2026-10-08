<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lws_authz\Form\TrustedIssuerForm;
use Drupal\lws_authz\TrustedIssuerListBuilder;

/**
 * A trusted OpenID Provider (DESIGN.md §6.4).
 */
#[ConfigEntityType(
  id: 'lws_trusted_issuer',
  label: new TranslatableMarkup('Trusted OpenID Provider'),
  label_collection: new TranslatableMarkup('LWS OpenID Providers'),
  label_singular: new TranslatableMarkup('trusted OpenID Provider'),
  label_plural: new TranslatableMarkup('trusted OpenID Providers'),
  config_prefix: 'trusted_issuer',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'status' => 'status',
  ],
  handlers: [
    'list_builder' => TrustedIssuerListBuilder::class,
    'form' => [
      'add' => TrustedIssuerForm::class,
      'edit' => TrustedIssuerForm::class,
      'delete' => EntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/config/services/lws/openid-providers',
    'add-form' => '/admin/config/services/lws/openid-providers/add',
    'edit-form' => '/admin/config/services/lws/openid-providers/{lws_trusted_issuer}',
    'delete-form' => '/admin/config/services/lws/openid-providers/{lws_trusted_issuer}/delete',
  ],
  admin_permission: 'administer lws',
  label_count: [
    'singular' => '@count trusted OpenID Provider',
    'plural' => '@count trusted OpenID Providers',
  ],
  config_export: [
    'id',
    'label',
    'status',
    'issuer',
    'jwks',
    'require_as_audience',
    'verify_subject',
  ],
)]
class TrustedIssuer extends ConfigEntityBase implements TrustedIssuerInterface {

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
   * Whether ID Tokens must name this authorization server in "aud".
   */
  protected bool $require_as_audience = TRUE;

  /**
   * Whether subjects' documents must name the provider.
   */
  protected bool $verify_subject = TRUE;

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
   */
  public function requiresAsAudience(): bool {
    return $this->require_as_audience;
  }

  /**
   * {@inheritdoc}
   */
  public function verifiesSubject(): bool {
    return $this->verify_subject;
  }

}
