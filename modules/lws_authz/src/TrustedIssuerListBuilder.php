<?php

declare(strict_types=1);

namespace Drupal\lws_authz;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\lws_authz\Entity\TrustedIssuerInterface;

/**
 * The trusted OpenID Providers.
 */
final class TrustedIssuerListBuilder extends ConfigEntityListBuilder {

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
      'audience' => $this->t('Requires this server in "aud"'),
      'subjects' => $this->t('Subjects'),
      'status' => $this->t('Status'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The row.
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof TrustedIssuerInterface);
    return [
      'label' => $entity->label(),
      'issuer' => $entity->getIssuer(),
      'keys' => $entity->getJwks() === NULL ? $this->t('Discovered') : $this->t('Pinned'),
      'audience' => $entity->requiresAsAudience() ? $this->t('Yes') : $this->t('No'),
      'subjects' => $entity->verifiesSubject() ? $this->t('Those whose documents name it') : $this->t('Any'),
      'status' => $entity->status() ? $this->t('Enabled') : $this->t('Disabled'),
    ] + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function render(): array {
    $build = parent::render();
    $build['table']['#empty'] = $this->t("No OpenID Providers are configured. An ID Token is still accepted from the provider that its subject's controlled identifier document names, if the authorization settings allow it.");
    return $build;
  }

}
