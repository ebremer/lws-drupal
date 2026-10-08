<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Policy;

/**
 * A constraint of an access policy (LWS Core §11.3.5, ODRL).
 */
final class Constraint {

  /**
   * The operators each left operand supports (DESIGN.md §6.5).
   *
   * A constraint with any other operand or operator is not valid. "type"
   * compares a set, the resource's types, so its "eq" means "includes".
   */
  public const OPERATORS = [
    'client' => ['eq', 'neq', 'isAnyOf', 'isNoneOf'],
    'format' => ['eq', 'neq', 'isAnyOf', 'isNoneOf'],
    'type' => ['eq', 'isAnyOf', 'isAllOf', 'isNoneOf'],
    'purpose' => ['eq', 'isAnyOf'],
    'dateTime' => ['eq', 'lt', 'lteq', 'gt', 'gteq'],
  ];

  /**
   * The operators whose right operand is a list.
   */
  public const LIST_OPERATORS = ['isAnyOf', 'isAllOf', 'isNoneOf'];

  /**
   * Constructs a constraint.
   *
   * @param string $leftOperand
   *   What is constrained.
   * @param string $operator
   *   How it is compared.
   * @param string|list<string> $rightOperand
   *   What it is compared with: a list for the list operators.
   */
  public function __construct(
    public readonly string $leftOperand,
    public readonly string $operator,
    public readonly string|array $rightOperand,
  ) {}

  /**
   * The constraint as JSON.
   *
   * @return array{leftOperand: string, operator: string, rightOperand: string|list<string>}
   *   The constraint object.
   */
  public function toJson(): array {
    return ['leftOperand' => $this->leftOperand, 'operator' => $this->operator, 'rightOperand' => $this->rightOperand];
  }

}
