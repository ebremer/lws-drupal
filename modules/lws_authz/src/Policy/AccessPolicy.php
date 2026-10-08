<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Policy;

use Ebremer\Lws\ResourceType;

/**
 * An access policy of the access profile (LWS Core §11.3).
 *
 * The policy model of the decision point (DESIGN.md D5): stored policies,
 * and those an access grant carries, are these.
 */
final class AccessPolicy {

  /**
   * The assignee for everyone, the public (FOAF).
   */
  public const PUBLIC = 'http://xmlns.com/foaf/0.1/Agent';

  /**
   * The assignee for every authenticated agent: an extension (DESIGN.md §12).
   */
  public const AUTHENTICATED = 'http://www.w3.org/ns/auth/acl#AuthenticatedAgent';

  /**
   * The actions of the profile.
   */
  public const ACTIONS = ['read', 'modify', 'create', 'delete'];

  /**
   * The target types of the profile.
   */
  public const TARGET_TYPES = [ResourceType::STORAGE_RESOURCE, ResourceType::CONTAINER, ResourceType::DATA_RESOURCE];

  /**
   * Constructs a policy.
   *
   * @param list<string> $actions
   *   The actions it permits, among ACTIONS.
   * @param string $assignee
   *   The agent it is for, or PUBLIC or AUTHENTICATED.
   * @param string $targetType
   *   The kind of resource it applies to, among TARGET_TYPES.
   * @param list<string> $targetValues
   *   The URIs of the resources it applies to, and of the containers whose
   *   resources at any depth it applies to.
   * @param list<\Drupal\lws_authz\Policy\Constraint> $constraints
   *   Conditions that must all hold.
   */
  public function __construct(
    public readonly array $actions,
    public readonly string $assignee,
    public readonly string $targetType,
    public readonly array $targetValues,
    public readonly array $constraints = [],
  ) {}

  /**
   * The JSON of a policy, from what an administrator chooses.
   *
   * @param string $assignee
   *   The agent, or PUBLIC or AUTHENTICATED.
   * @param list<string> $actions
   *   The actions.
   * @param string $targetType
   *   The target type, as a term or URI.
   * @param list<string> $targets
   *   The target URIs.
   * @param string|null $until
   *   An xsd:dateTime after which it permits nothing, if any.
   * @param string|null $client
   *   The only client it permits, if any.
   *
   * @return array<string, mixed>
   *   The AccessPolicy object, for AccessPolicyParser.
   */
  public static function document(string $assignee, array $actions, string $targetType, array $targets, ?string $until = NULL, ?string $client = NULL): array {
    $constraints = [];
    if ($until !== NULL) {
      $constraints[] = (new Constraint('dateTime', 'lteq', $until))->toJson();
    }
    if ($client !== NULL) {
      $constraints[] = (new Constraint('client', 'eq', $client))->toJson();
    }
    return [
      'type' => [ResourceType::ACCESS_POLICY],
      'action' => $actions,
      'assignee' => $assignee,
      'target' => ['type' => $targetType, 'value' => $targets],
      'constraint' => $constraints,
    ];
  }

  /**
   * The policy as JSON, as an access request or grant carries it.
   *
   * @return array<string, mixed>
   *   The AccessPolicy object.
   */
  public function toJson(): array {
    $json = [
      'type' => [ResourceType::ACCESS_POLICY],
      'action' => $this->actions,
      'assignee' => $this->assignee,
      'target' => [
        'type' => substr($this->targetType, strlen('https://www.w3.org/ns/lws#')),
        'value' => $this->targetValues,
      ],
    ];
    if ($this->constraints !== []) {
      $json['constraint'] = array_map(static fn (Constraint $constraint): array => $constraint->toJson(), $this->constraints);
    }
    return $json;
  }

  /**
   * The latest time it can permit anything, from its dateTime constraints.
   *
   * @return int|null
   *   A Unix time; NULL if no constraint ends it.
   */
  public function notAfter(): ?int {
    $end = NULL;
    foreach ($this->constraints as $constraint) {
      if ($constraint->leftOperand === 'dateTime' && in_array($constraint->operator, ['lt', 'lteq', 'eq'], TRUE) && is_string($constraint->rightOperand)) {
        $time = PolicyEvaluator::dateTime($constraint->rightOperand);
        if ($time !== NULL && ($end === NULL || $time < $end)) {
          $end = $time;
        }
      }
    }
    return $end;
  }

}
