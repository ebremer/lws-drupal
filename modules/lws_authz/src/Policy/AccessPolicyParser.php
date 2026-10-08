<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Policy;

use Drupal\lws\Routing\LwsArea;
use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws\Routing\LwsUrlParser;
use Drupal\lws\Storage\StorageRef;
use Ebremer\Lws\Json\Json;
use Ebremer\Lws\ResourceType;
use Ebremer\Lws\Vocabulary;

/**
 * Reads access policies of one storage from their JSON form (§11.3).
 *
 * Validation is strict, since a policy grants access:
 *
 * - "type" includes AccessPolicy;
 * - "action" is a non-empty list of the profile's actions;
 * - "assignee" is an absolute URI;
 * - "target" is required (DESIGN.md §12), and its values are URIs of the
 *   storage or of resources in it, which are made canonical: the storage
 *   URI, or a container or data resource as the server writes its URI;
 * - every constraint has an operand and operator the decision point
 *   evaluates, and a right operand of the right shape. A dateTime must have
 *   a time zone, or it would not say when.
 *
 * Other members, such as "@context" and "id", are ignored.
 */
final class AccessPolicyParser {

  /**
   * An absolute URI.
   */
  private const URI = '/^[A-Za-z][A-Za-z0-9+.-]*:\S+$/';

  /**
   * A media type, without parameters.
   */
  private const MEDIA_TYPE = '/^[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*\/[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*$/';

  public function __construct(
    private readonly LwsUrlParser $parser,
    private readonly LwsUrlGenerator $urls,
  ) {}

  /**
   * Reads a policy.
   *
   * @param mixed $json
   *   The AccessPolicy object, decoded.
   * @param \Drupal\lws\Storage\StorageRef $storage
   *   The storage it is for.
   *
   * @throws \Drupal\lws_authz\Policy\InvalidPolicyException
   *   When it is not a valid policy for the storage.
   */
  public function parse(mixed $json, StorageRef $storage): AccessPolicy {
    $policy = Json::members($json) ?? throw new InvalidPolicyException('An access policy is a JSON object.');
    $types = self::strings($policy['type'] ?? NULL, TRUE);
    $policyTypes = [ResourceType::ACCESS_POLICY, Vocabulary::LWS_NS . ResourceType::ACCESS_POLICY];
    if ($types === NULL || array_intersect($types, $policyTypes) === []) {
      throw new InvalidPolicyException('The policy\'s "type" must include AccessPolicy.');
    }

    $actions = self::strings($policy['action'] ?? NULL, FALSE);
    if ($actions === NULL || $actions === [] || array_diff($actions, AccessPolicy::ACTIONS) !== []) {
      throw new InvalidPolicyException(sprintf('The policy\'s "action" must be a list of %s.', implode(', ', AccessPolicy::ACTIONS)));
    }

    $assignee = $policy['assignee'] ?? NULL;
    if (!is_string($assignee) || preg_match(self::URI, $assignee) !== 1) {
      throw new InvalidPolicyException('The policy\'s "assignee" must be a URI.');
    }

    $target = Json::members($policy['target'] ?? NULL) ?? throw new InvalidPolicyException('The policy needs a "target": its type and the resources it applies to.');
    $targetType = $target['type'] ?? ResourceType::STORAGE_RESOURCE;
    $targetType = is_string($targetType) && !str_contains($targetType, ':') ? Vocabulary::LWS_NS . $targetType : $targetType;
    if (!in_array($targetType, AccessPolicy::TARGET_TYPES, TRUE)) {
      throw new InvalidPolicyException('The target\'s "type" must be StorageResource, Container or DataResource.');
    }
    $values = self::strings($target['value'] ?? NULL, TRUE);
    if ($values === NULL || $values === []) {
      throw new InvalidPolicyException('The target\'s "value" must list the resources it applies to.');
    }
    $values = array_map(fn (string $value): string => $this->canonical($value, $storage), $values);

    $constraints = [];
    $list = $policy['constraint'] ?? [];
    if (!Json::isList($list)) {
      throw new InvalidPolicyException('The policy\'s "constraint" must be a list.');
    }
    foreach ($list as $constraint) {
      $constraints[] = self::constraint($constraint);
    }

    return new AccessPolicy(
      array_values(array_intersect(AccessPolicy::ACTIONS, $actions)),
      $assignee,
      $targetType,
      array_values(array_unique($values)),
      $constraints,
    );
  }

  /**
   * The canonical form of a target URI, which must be in the storage.
   *
   * @throws \Drupal\lws_authz\Policy\InvalidPolicyException
   */
  private function canonical(string $uri, StorageRef $storage): string {
    $base = $this->urls->baseUrl();
    $parts = parse_url($uri);
    if (!str_starts_with($uri, $base . '/') || $parts === FALSE || isset($parts['query']) || isset($parts['fragment'])) {
      throw new InvalidPolicyException(sprintf('The target %s is not in the storage %s.', $uri, $storage->uri));
    }
    $path = substr($uri, strlen($base));
    // The storage URI and containers end in a slash; a container may be
    // named without it.
    $target = $this->parser->parse($path);
    if ($target !== NULL && $target->area !== LwsArea::Description && $target->area !== LwsArea::Resource) {
      $target = str_ends_with($path, '/') ? NULL : $this->parser->parse($path . '/');
    }
    $inStorage = $target !== NULL && $target->storage === $storage->slug
      && ($target->area === LwsArea::Description || $target->area === LwsArea::Resource);
    $canonical = $inStorage ? $this->urls->targetUri($target) : NULL;
    if ($canonical === NULL) {
      throw new InvalidPolicyException(sprintf('The target %s is not in the storage %s.', $uri, $storage->uri));
    }
    return $canonical;
  }

  /**
   * Reads a constraint.
   *
   * @throws \Drupal\lws_authz\Policy\InvalidPolicyException
   */
  private static function constraint(mixed $json): Constraint {
    $constraint = Json::members($json) ?? throw new InvalidPolicyException('A constraint is a JSON object.');
    $operand = $constraint['leftOperand'] ?? NULL;
    $operator = $constraint['operator'] ?? NULL;
    if (!is_string($operand) || !isset(Constraint::OPERATORS[$operand])) {
      throw new InvalidPolicyException(sprintf('A constraint\'s "leftOperand" must be one of %s.', implode(', ', array_keys(Constraint::OPERATORS))));
    }
    if (!is_string($operator) || !in_array($operator, Constraint::OPERATORS[$operand], TRUE)) {
      throw new InvalidPolicyException(sprintf('A %s constraint\'s "operator" must be one of %s.', $operand, implode(', ', Constraint::OPERATORS[$operand])));
    }
    $right = $constraint['rightOperand'] ?? NULL;
    // A JSON-LD value object, such as a typed xsd:dateTime.
    $value = Json::members($right);
    if ($value !== NULL && array_key_exists('@value', $value)) {
      $right = $value['@value'];
    }
    $isList = in_array($operator, Constraint::LIST_OPERATORS, TRUE);
    $values = $isList ? self::strings($right, FALSE) : (is_string($right) ? [$right] : NULL);
    if ($values === NULL || $values === []) {
      throw new InvalidPolicyException(sprintf('The %s constraint with %s needs %s as its "rightOperand".', $operand, $operator, $isList ? 'a list of strings' : 'a string'));
    }
    foreach ($values as $i => $value) {
      $values[$i] = match ($operand) {
        'format' => preg_match(self::MEDIA_TYPE, $value) === 1 ? strtolower($value) : NULL,
        'type' => preg_match(self::URI, $value) === 1 ? $value : (preg_match('/^[A-Za-z]+$/', $value) === 1 ? Vocabulary::LWS_NS . $value : NULL),
        'dateTime' => PolicyEvaluator::dateTime($value) === NULL ? NULL : $value,
        default => preg_match(self::URI, $value) === 1 ? $value : NULL,
      } ?? throw new InvalidPolicyException(sprintf('"%s" is not a valid right operand of a %s constraint%s.', $value, $operand, $operand === 'dateTime' ? ': use an xsd:dateTime with a time zone' : ''));
    }
    return new Constraint($operand, $operator, $isList ? $values : $values[0]);
  }

  /**
   * A list of strings, or with $single also a lone string; NULL otherwise.
   *
   * @return list<string>|null
   *   The strings.
   */
  private static function strings(mixed $value, bool $single): ?array {
    if ($single && is_string($value)) {
      return [$value];
    }
    if (!Json::isList($value)) {
      return NULL;
    }
    foreach ($value as $item) {
      if (!is_string($item)) {
        return NULL;
      }
    }
    return array_values($value);
  }

}
