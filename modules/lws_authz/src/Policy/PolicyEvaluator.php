<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Policy;

use Drupal\lws\Access\Action;
use Drupal\lws\Access\ResourceContext;
use Drupal\lws\Agent\RequestingAgent;
use Ebremer\Lws\ResourceType;

/**
 * Whether an access policy permits an action (DESIGN.md §6.5).
 *
 * A policy permits an action on a resource when:
 *
 * - the action is one of its actions;
 * - its assignee is the agent, the public, or, for an authenticated agent,
 *   every authenticated agent or a group the agent is in;
 * - its target matches: the resource is of its target type (any, for
 *   StorageResource), and one of its target values is the resource, a
 *   container above it, or the storage (D6). A container may be named with
 *   or without its trailing slash. Create is judged on the container the
 *   resource is created in;
 * - all its constraints hold.
 *
 * Constraints fail closed: what cannot be evaluated does not hold. So a
 * format constraint never holds for a container, which has no format, nor a
 * format or type constraint for a resource that does not exist, whose
 * format and types are unknown. A purpose constraint never holds, as no
 * request states a purpose (D7). For Create, format and type are those of
 * the new resource; a modification must satisfy them both before and after,
 * and cannot satisfy a type constraint when its outcome is unknown.
 */
final class PolicyEvaluator {

  /**
   * An XML Schema dateTime with a time zone.
   */
  private const DATE_TIME = '/^-?\d{4,}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/';

  /**
   * Whether a policy permits an action.
   */
  public static function permits(AccessPolicy $policy, Action $action, RequestingAgent $agent, ResourceContext $resource, int $now): bool {
    return in_array($action->value, $policy->actions, TRUE)
      && self::assigned($policy, $agent)
      && self::targets($policy, $resource)
      && self::holds($policy->constraints, $action, $agent, $resource, $now);
  }

  /**
   * Whether a policy is for an agent.
   */
  public static function assigned(AccessPolicy $policy, RequestingAgent $agent): bool {
    if ($policy->assignee === AccessPolicy::PUBLIC) {
      return TRUE;
    }
    $assignees = [AccessPolicy::AUTHENTICATED, $agent->subject, ...$agent->groups];
    return $agent->isAuthenticated() && in_array($policy->assignee, $assignees, TRUE);
  }

  /**
   * Whether a policy's target matches a resource.
   */
  public static function targets(AccessPolicy $policy, ResourceContext $resource): bool {
    $kind = match ($policy->targetType) {
      ResourceType::STORAGE_RESOURCE => TRUE,
      ResourceType::CONTAINER => $resource->container,
      ResourceType::DATA_RESOURCE => !$resource->container,
      default => FALSE,
    };
    return $kind && self::covers($policy, $resource);
  }

  /**
   * Whether a policy's target values name a resource, or a container above.
   *
   * URIs are compared exactly: "notes" and "notes/" are two resources, which
   * may both exist, so a policy on the one never covers the other, nor what
   * is in it.
   */
  public static function covers(AccessPolicy $policy, ResourceContext $resource): bool {
    $scope = [$resource->uri, ...$resource->ancestors, $resource->storage->uri];
    foreach ($policy->targetValues as $value) {
      if (in_array($value, $scope, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether all constraints hold.
   *
   * @param list<\Drupal\lws_authz\Policy\Constraint> $constraints
   *   The constraints.
   * @param \Drupal\lws\Access\Action $action
   *   The action.
   * @param \Drupal\lws\Agent\RequestingAgent $agent
   *   The agent.
   * @param \Drupal\lws\Access\ResourceContext $resource
   *   The resource.
   * @param int $now
   *   The time of the request, as a Unix time.
   */
  public static function holds(array $constraints, Action $action, RequestingAgent $agent, ResourceContext $resource, int $now): bool {
    foreach ($constraints as $constraint) {
      // Stored policies were validated, but evaluation trusts nothing.
      if (!in_array($constraint->operator, Constraint::OPERATORS[$constraint->leftOperand] ?? [], TRUE)) {
        return FALSE;
      }
      $holds = match ($constraint->leftOperand) {
        'client' => $agent->client !== NULL && self::compare([$agent->client], $constraint),
        'format' => self::all(self::formats($action, $resource), static fn (string $format): bool => self::compare([$format], $constraint)),
        'type' => self::all(self::typeSets($action, $resource), static fn (array $types): bool => self::compare(array_values($types), $constraint)),
        'dateTime' => self::compareTime($now, $constraint),
        default => FALSE,
      };
      if (!$holds) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Whether a constraint depends on the resource rather than the request.
   *
   * Others hold for every resource alike.
   */
  public static function dependsOnResource(Constraint $constraint): bool {
    return in_array($constraint->leftOperand, ['format', 'type'], TRUE);
  }

  /**
   * An xsd:dateTime with a time zone as a Unix time; NULL if it is not one.
   */
  public static function dateTime(string $value): ?int {
    if (preg_match(self::DATE_TIME, $value) !== 1) {
      return NULL;
    }
    try {
      return (new \DateTimeImmutable($value))->getTimestamp();
    }
    catch (\Exception) {
      return NULL;
    }
  }

  /**
   * The media types a format constraint must hold for; NULL for one unknown.
   *
   * @return list<string|null>
   *   The media types, without parameters.
   */
  private static function formats(Action $action, ResourceContext $resource): array {
    $formats = $action === Action::Create ? [$resource->change?->mediaType] : [$resource->mediaType];
    if ($action !== Action::Create && $resource->change?->mediaType !== NULL) {
      $formats[] = $resource->change->mediaType;
    }
    return array_map(static fn (?string $format): ?string => $format === NULL ? NULL : strtolower(trim(explode(';', $format)[0])), $formats);
  }

  /**
   * The sets of types a type constraint must hold for; NULL for one unknown.
   *
   * @return list<list<string>|null>
   *   The sets.
   */
  private static function typeSets(Action $action, ResourceContext $resource): array {
    $change = $resource->change;
    if ($action === Action::Create) {
      return [$change?->types];
    }
    // A resource always has its LWS class, so it has no types only if it
    // does not exist.
    $sets = [$resource->types === [] ? NULL : $resource->types];
    if ($change !== NULL && ($change->types !== NULL || $change->typesUnknown)) {
      $sets[] = $change->typesUnknown ? NULL : $change->types;
    }
    return $sets;
  }

  /**
   * Whether a test holds for every value, all of which must be known.
   *
   * @param list<mixed> $values
   *   The values; NULL is unknown.
   * @param callable(mixed): bool $test
   *   The test.
   */
  private static function all(array $values, callable $test): bool {
    foreach ($values as $value) {
      if ($value === NULL || !$test($value)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Compares a set of values, often of one, with a constraint's operand.
   *
   * @param list<string> $values
   *   The values of the request or resource.
   * @param \Drupal\lws_authz\Policy\Constraint $constraint
   *   The constraint.
   */
  private static function compare(array $values, Constraint $constraint): bool {
    $right = (array) $constraint->rightOperand;
    if ($constraint->leftOperand === 'format') {
      $right = array_map('strtolower', $right);
    }
    $common = array_intersect($values, $right);
    return match ($constraint->operator) {
      'eq', 'isAnyOf' => $common !== [],
      'neq', 'isNoneOf' => $common === [],
      'isAllOf' => array_diff($right, $values) === [],
      default => FALSE,
    };
  }

  /**
   * Compares the request time with a dateTime constraint.
   */
  private static function compareTime(int $now, Constraint $constraint): bool {
    $bound = is_string($constraint->rightOperand) ? self::dateTime($constraint->rightOperand) : NULL;
    return $bound !== NULL && match ($constraint->operator) {
      'eq' => $now === $bound,
      'lt' => $now < $bound,
      'lteq' => $now <= $bound,
      'gt' => $now > $bound,
      'gteq' => $now >= $bound,
      default => FALSE,
    };
  }

}
