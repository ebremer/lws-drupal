<?php

declare(strict_types=1);

namespace Drupal\lws_authz\AuthenticationSuite;

use Drupal\Core\Plugin\PluginBase;

/**
 * Base class for authentication suites.
 */
abstract class AuthenticationSuiteBase extends PluginBase implements AuthenticationSuiteInterface {

  /**
   * {@inheritdoc}
   */
  public function tokenType(): string {
    return (string) $this->definition()['token_type'];
  }

  /**
   * {@inheritdoc}
   */
  public function subjectIdentifierTypes(): array {
    return array_values((array) ($this->definition()['subject_identifier_types'] ?? []));
  }

  /**
   * The plugin definition, which the attribute makes an array.
   *
   * @return array<string, mixed>
   *   The definition.
   */
  private function definition(): array {
    $definition = $this->getPluginDefinition();
    assert(is_array($definition));
    return $definition;
  }

}
