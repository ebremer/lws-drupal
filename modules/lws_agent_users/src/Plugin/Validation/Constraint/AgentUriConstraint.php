<?php

declare(strict_types=1);

namespace Drupal\lws_agent_users\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * An agent URI a user can be linked to.
 */
#[Constraint(
  id: 'LwsAgentUri',
  label: new TranslatableMarkup('LWS agent URI', [], ['context' => 'Validation']),
)]
final class AgentUriConstraint extends SymfonyConstraint {

  /**
   * The message for what is not an absolute URI.
   */
  public string $notUri = 'The LWS agent URI must be an absolute URI, such as https://alice.example/profile#me or a DID.';

  /**
   * The message for the agent URI of a user of this site (lws_identity).
   */
  public string $local = 'This is a user&rsquo;s own agent URI at this site, which acts as that user already.';

  /**
   * The message for an agent URI linked to another user.
   */
  public string $taken = 'This agent already acts as %user.';

}
