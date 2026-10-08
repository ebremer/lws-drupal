<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_authz\Unit\Policy;

use Drupal\lws\Access\Action;
use Drupal\lws\Access\ResourceChange;
use Drupal\lws\Access\ResourceContext;
use Drupal\lws\Agent\RequestingAgent;
use Drupal\lws\Storage\StorageRef;
use Drupal\lws_authz\Policy\AccessPolicy;
use Drupal\lws_authz\Policy\Constraint;
use Drupal\lws_authz\Policy\PolicyEvaluator;
use Drupal\Tests\UnitTestCase;
use Ebremer\Lws\ResourceType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests access policy evaluation: targets, assignees and constraints.
 *
 * Everything that cannot be evaluated must deny.
 */
#[CoversClass(PolicyEvaluator::class)]
#[Group('lws')]
final class PolicyEvaluatorTest extends UnitTestCase {

  private const STORAGE = 'https://storage.example/lws/alice/';

  private const ROOT = self::STORAGE . 'root/';

  private const BOB = 'https://id.example/bob';

  private const APP = 'https://app.example/id';

  private const NOW = 1790000000;

  /**
   * A resource context in the storage.
   *
   * @param string $path
   *   The path below the root container; a container's ends in a slash.
   * @param string|null $mediaType
   *   For an existing data resource, its media type.
   * @param list<string>|null $types
   *   For an existing resource, its user types; NULL when it does not exist.
   */
  private static function resource(string $path, ?string $mediaType = NULL, ?array $types = []): ResourceContext {
    $container = $path === '' || str_ends_with($path, '/');
    $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));
    $ancestors = [self::ROOT];
    for ($i = 1; $i < count($segments); $i++) {
      $ancestors[] = self::ROOT . implode('/', array_slice($segments, 0, $i)) . '/';
    }
    if ($path === '') {
      $ancestors = [];
    }
    $class = $container ? ResourceType::CONTAINER : ResourceType::DATA_RESOURCE;
    return new ResourceContext(
      new StorageRef(1, 'alice', self::STORAGE, ['https://id.example/alice']),
      self::ROOT . $path,
      $ancestors,
      $container,
      $mediaType,
      $types === NULL ? [] : [$class, ...$types],
    );
  }

  /**
   * A policy for bob.
   *
   * @param list<string> $actions
   *   The actions.
   * @param string $targetType
   *   The target type.
   * @param list<string> $targets
   *   The target values.
   * @param list<\Drupal\lws_authz\Policy\Constraint> $constraints
   *   The constraints.
   * @param string $assignee
   *   The assignee.
   */
  private static function policy(array $actions = ['read'], string $targetType = ResourceType::STORAGE_RESOURCE, array $targets = [self::ROOT . 'shared/'], array $constraints = [], string $assignee = self::BOB): AccessPolicy {
    return new AccessPolicy($actions, $assignee, $targetType, $targets, $constraints);
  }

  /**
   * Bob, using the app.
   */
  private static function bob(): RequestingAgent {
    return new RequestingAgent(self::BOB, self::APP, 'https://as.example', 'jti');
  }

  /**
   * Tests targets: the matrix of target types, resource kinds and places.
   */
  #[DataProvider('targetProvider')]
  public function testTargets(string $targetType, string $target, string $path, bool $permits): void {
    $policy = self::policy(['read'], $targetType, [$target]);
    $this->assertSame($permits, PolicyEvaluator::permits($policy, Action::Read, self::bob(), self::resource($path, 'text/plain'), self::NOW));
  }

  /**
   * Data provider for testTargets().
   *
   * @return array<string, array{string, string, string, bool}>
   *   Target type, target value, the resource's path, whether it permits.
   */
  public static function targetProvider(): array {
    $cases = [];
    $kinds = [
      'StorageResource' => ResourceType::STORAGE_RESOURCE,
      'Container' => ResourceType::CONTAINER,
      'DataResource' => ResourceType::DATA_RESOURCE,
    ];
    $places = [
      // Target, resource, whether the target covers it.
      'itself (data)' => [self::ROOT . 'shared/a.txt', 'shared/a.txt', TRUE],
      'itself (container)' => [self::ROOT . 'shared/', 'shared/', TRUE],
      'the container without its slash' => [self::ROOT . 'shared', 'shared/', TRUE],
      'below the container' => [self::ROOT . 'shared/', 'shared/deep/b.txt', TRUE],
      'below, a container' => [self::ROOT . 'shared/', 'shared/deep/', TRUE],
      'the storage' => [self::STORAGE, 'other/c.txt', TRUE],
      'the root' => [self::ROOT, 'other/', TRUE],
      'beside' => [self::ROOT . 'shared/', 'private/d.txt', FALSE],
      'a name that starts the same' => [self::ROOT . 'shared/', 'shared2/e.txt', FALSE],
      'above' => [self::ROOT . 'shared/a.txt', 'shared/', FALSE],
    ];
    foreach ($kinds as $kind => $type) {
      foreach ($places as $place => [$target, $path, $covers]) {
        $container = str_ends_with($path, '/');
        $kindOk = $kind === 'StorageResource' || ($kind === 'Container') === $container;
        $cases["$kind, $place"] = [$type, $target, $path, $covers && $kindOk];
      }
    }
    $cases['an unknown target type'] = ['https://example.org/Other', self::ROOT, 'a.txt', FALSE];
    return $cases;
  }

  /**
   * Tests actions and assignees.
   */
  public function testActionsAndAssignees(): void {
    $resource = self::resource('shared/a.txt', 'text/plain');
    $anonymous = RequestingAgent::anonymous();
    $carol = new RequestingAgent('https://id.example/carol', self::APP, 'https://as.example', 'jti');
    foreach (Action::cases() as $action) {
      $actions = $action === Action::Control ? AccessPolicy::ACTIONS : [$action->value];
      $this->assertSame($action !== Action::Control, PolicyEvaluator::permits(self::policy($actions), $action, self::bob(), $resource, self::NOW), $action->value);
      // Every other action.
      $others = array_values(array_diff(AccessPolicy::ACTIONS, [$action->value]));
      $this->assertFalse(PolicyEvaluator::permits(self::policy($others), $action, self::bob(), $resource, self::NOW), $action->value);
    }
    // Bob's policy is not carol's, nor anonymous'.
    $this->assertFalse(PolicyEvaluator::permits(self::policy(), Action::Read, $carol, $resource, self::NOW));
    $this->assertFalse(PolicyEvaluator::permits(self::policy(), Action::Read, $anonymous, $resource, self::NOW));
    // The public's applies to everyone; every authenticated agent's does not
    // apply to the anonymous.
    $public = self::policy(assignee: AccessPolicy::PUBLIC);
    $authenticated = self::policy(assignee: AccessPolicy::AUTHENTICATED);
    foreach ([self::bob(), $carol, $anonymous] as $agent) {
      $this->assertTrue(PolicyEvaluator::permits($public, Action::Read, $agent, $resource, self::NOW));
      $this->assertSame($agent->isAuthenticated(), PolicyEvaluator::permits($authenticated, Action::Read, $agent, $resource, self::NOW));
    }
  }

  /**
   * Tests constraints: the matrix of operands, operators and values.
   *
   * @param \Drupal\lws_authz\Policy\Constraint $constraint
   *   The constraint.
   * @param \Drupal\lws\Agent\RequestingAgent $agent
   *   The agent.
   * @param \Drupal\lws\Access\ResourceContext $resource
   *   The resource.
   * @param bool $holds
   *   Whether it holds.
   */
  #[DataProvider('constraintProvider')]
  public function testConstraints(Constraint $constraint, RequestingAgent $agent, ResourceContext $resource, bool $holds): void {
    $this->assertSame($holds, PolicyEvaluator::holds([$constraint], Action::Read, $agent, $resource, self::NOW));
  }

  /**
   * Data provider for testConstraints().
   *
   * @return array<string, array{\Drupal\lws_authz\Policy\Constraint, \Drupal\lws\Agent\RequestingAgent, \Drupal\lws\Access\ResourceContext, bool}>
   *   The constraint, agent, resource and whether it holds.
   */
  public static function constraintProvider(): array {
    $bob = self::bob();
    $anonymous = RequestingAgent::anonymous();
    $png = self::resource('shared/a.png', 'image/png', ['https://type.example/Photo']);
    $text = self::resource('shared/a.txt', 'text/plain; charset=utf-8', []);
    $missing = self::resource('shared/missing.png', NULL, NULL);
    $folder = self::resource('shared/', NULL, []);
    $other = 'https://other.example/id';
    $photo = 'https://type.example/Photo';
    $song = 'https://type.example/Song';
    $before = gmdate('Y-m-d\TH:i:s\Z', self::NOW - 60);
    $at = gmdate('Y-m-d\TH:i:s\Z', self::NOW);
    $after = gmdate('Y-m-d\TH:i:sP', self::NOW + 60);
    $unzoned = gmdate('Y-m-d\TH:i:s', self::NOW + 60);
    $c = static fn (string $left, string $operator, string|array $right): Constraint => new Constraint(
      $left,
      $operator,
      is_array($right) ? array_values(array_map('strval', $right)) : $right,
    );
    return [
      // The client.
      'client eq' => [$c('client', 'eq', self::APP), $bob, $png, TRUE],
      'client eq, another' => [$c('client', 'eq', $other), $bob, $png, FALSE],
      'client neq' => [$c('client', 'neq', $other), $bob, $png, TRUE],
      'client neq, its own' => [$c('client', 'neq', self::APP), $bob, $png, FALSE],
      'client isAnyOf' => [$c('client', 'isAnyOf', [$other, self::APP]), $bob, $png, TRUE],
      'client isAnyOf, none' => [$c('client', 'isAnyOf', [$other]), $bob, $png, FALSE],
      'client isNoneOf' => [$c('client', 'isNoneOf', [$other]), $bob, $png, TRUE],
      'client isNoneOf, its own' => [$c('client', 'isNoneOf', [self::APP]), $bob, $png, FALSE],
      'client, anonymous: unknown' => [$c('client', 'neq', $other), $anonymous, $png, FALSE],
      'client isAllOf: not an operator of client' => [$c('client', 'isAllOf', [self::APP]), $bob, $png, FALSE],
      // The format.
      'format eq' => [$c('format', 'eq', 'image/png'), $bob, $png, TRUE],
      'format eq, case' => [$c('format', 'eq', 'IMAGE/PNG'), $bob, $png, TRUE],
      'format eq, parameters' => [$c('format', 'eq', 'text/plain'), $bob, $text, TRUE],
      'format eq, another' => [$c('format', 'eq', 'image/jpeg'), $bob, $png, FALSE],
      'format neq' => [$c('format', 'neq', 'image/jpeg'), $bob, $png, TRUE],
      'format neq, its own' => [$c('format', 'neq', 'image/png'), $bob, $png, FALSE],
      'format isAnyOf' => [$c('format', 'isAnyOf', ['image/jpeg', 'image/png']), $bob, $png, TRUE],
      'format isNoneOf' => [$c('format', 'isNoneOf', ['image/jpeg']), $bob, $png, TRUE],
      'format isNoneOf, its own' => [$c('format', 'isNoneOf', ['image/png']), $bob, $png, FALSE],
      'format of a container: none' => [$c('format', 'neq', 'image/png'), $bob, $folder, FALSE],
      'format of nothing: unknown' => [$c('format', 'isNoneOf', ['image/jpeg']), $bob, $missing, FALSE],
      // The types.
      'type eq: includes' => [$c('type', 'eq', $photo), $bob, $png, TRUE],
      'type eq, the LWS class' => [$c('type', 'eq', ResourceType::DATA_RESOURCE), $bob, $png, TRUE],
      'type eq, another' => [$c('type', 'eq', $song), $bob, $png, FALSE],
      'type isAnyOf' => [$c('type', 'isAnyOf', [$song, $photo]), $bob, $png, TRUE],
      'type isAllOf' => [$c('type', 'isAllOf', [$photo, ResourceType::DATA_RESOURCE]), $bob, $png, TRUE],
      'type isAllOf, one missing' => [$c('type', 'isAllOf', [$photo, $song]), $bob, $png, FALSE],
      'type isNoneOf' => [$c('type', 'isNoneOf', [$song]), $bob, $png, TRUE],
      'type isNoneOf, its own' => [$c('type', 'isNoneOf', [$photo]), $bob, $png, FALSE],
      'type of nothing: unknown' => [$c('type', 'isNoneOf', [$song]), $bob, $missing, FALSE],
      'type neq: not an operator of type' => [$c('type', 'neq', $song), $bob, $png, FALSE],
      // The time.
      'dateTime lt' => [$c('dateTime', 'lt', $after), $bob, $png, TRUE],
      'dateTime lt, the end' => [$c('dateTime', 'lt', $at), $bob, $png, FALSE],
      'dateTime lteq, the end' => [$c('dateTime', 'lteq', $at), $bob, $png, TRUE],
      'dateTime lteq, past' => [$c('dateTime', 'lteq', $before), $bob, $png, FALSE],
      'dateTime gt' => [$c('dateTime', 'gt', $before), $bob, $png, TRUE],
      'dateTime gt, the start' => [$c('dateTime', 'gt', $at), $bob, $png, FALSE],
      'dateTime gteq, the start' => [$c('dateTime', 'gteq', $at), $bob, $png, TRUE],
      'dateTime gteq, future' => [$c('dateTime', 'gteq', $after), $bob, $png, FALSE],
      'dateTime eq' => [$c('dateTime', 'eq', $at), $bob, $png, TRUE],
      'dateTime without a time zone' => [$c('dateTime', 'lt', $unzoned), $bob, $png, FALSE],
      'dateTime not a date' => [$c('dateTime', 'lt', 'tomorrow'), $bob, $png, FALSE],
      'dateTime neq: not an operator of dateTime' => [$c('dateTime', 'neq', $before), $bob, $png, FALSE],
      // What cannot be evaluated.
      'purpose: no request states one' => [$c('purpose', 'eq', 'https://purpose.example/research'), $bob, $png, FALSE],
      'an unknown operand' => [$c('spatial', 'eq', 'https://place.example/'), $bob, $png, FALSE],
      'an unknown operator' => [$c('client', 'hasPart', self::APP), $bob, $png, FALSE],
      'an empty constraint, as an unreadable one is stored' => [$c('', '', ''), $bob, $png, FALSE],
    ];
  }

  /**
   * Tests that all constraints must hold.
   */
  public function testAllConstraints(): void {
    $png = self::resource('shared/a.png', 'image/png');
    $client = new Constraint('client', 'eq', self::APP);
    $format = new Constraint('format', 'eq', 'image/png');
    $wrong = new Constraint('format', 'eq', 'image/jpeg');
    $this->assertTrue(PolicyEvaluator::permits(self::policy(constraints: [$client, $format]), Action::Read, self::bob(), $png, self::NOW));
    $this->assertFalse(PolicyEvaluator::permits(self::policy(constraints: [$client, $wrong]), Action::Read, self::bob(), $png, self::NOW));
  }

  /**
   * Tests constraints on writes, judged on what they would make.
   */
  public function testWrites(): void {
    $bob = self::bob();
    $photo = 'https://type.example/Photo';
    $png = new Constraint('format', 'eq', 'image/png');
    $isPhoto = new Constraint('type', 'eq', $photo);
    $folder = self::resource('shared/', NULL, []);

    // Create: the new resource's format and types, in the container.
    $newPng = $folder->withChange(new ResourceChange('image/png', [ResourceType::DATA_RESOURCE, $photo]));
    $newText = $folder->withChange(new ResourceChange('text/plain', [ResourceType::DATA_RESOURCE]));
    $newFolder = $folder->withChange(new ResourceChange(NULL, [ResourceType::CONTAINER]));
    $this->assertTrue(PolicyEvaluator::holds([$png, $isPhoto], Action::Create, $bob, $newPng, self::NOW));
    $this->assertFalse(PolicyEvaluator::holds([$png], Action::Create, $bob, $newText, self::NOW));
    $this->assertFalse(PolicyEvaluator::holds([$isPhoto], Action::Create, $bob, $newText, self::NOW));
    $this->assertFalse(PolicyEvaluator::holds([$png], Action::Create, $bob, $newFolder, self::NOW));
    $this->assertFalse(PolicyEvaluator::holds([$png], Action::Create, $bob, $folder, self::NOW));
    // Create is judged on the container.
    $created = self::policy(['create'], ResourceType::CONTAINER, [self::ROOT . 'shared/']);
    $this->assertTrue(PolicyEvaluator::permits($created, Action::Create, $bob, $newText, self::NOW));
    $this->assertFalse(PolicyEvaluator::permits(self::policy(['create'], ResourceType::DATA_RESOURCE), Action::Create, $bob, $newText, self::NOW));

    // Modify: before and after.
    $existing = self::resource('shared/a.png', 'image/png', [$photo]);
    $this->assertTrue(PolicyEvaluator::holds([$png, $isPhoto], Action::Modify, $bob, $existing, self::NOW));
    $this->assertTrue(PolicyEvaluator::holds([$png], Action::Modify, $bob, $existing->withChange(new ResourceChange('image/png')), self::NOW));
    $this->assertFalse(PolicyEvaluator::holds([$png], Action::Modify, $bob, $existing->withChange(new ResourceChange('text/html')), self::NOW));
    $this->assertFalse(PolicyEvaluator::holds([$isPhoto], Action::Modify, $bob, $existing->withChange(new ResourceChange(types: [ResourceType::DATA_RESOURCE])), self::NOW));
    $stillPhoto = $existing->withChange(new ResourceChange(types: [ResourceType::DATA_RESOURCE, $photo]));
    $this->assertTrue(PolicyEvaluator::holds([$isPhoto], Action::Modify, $bob, $stillPhoto, self::NOW));
    // A linkset write, whose outcome is not known.
    $this->assertFalse(PolicyEvaluator::holds([$isPhoto], Action::Modify, $bob, $existing->withChange(new ResourceChange(typesUnknown: TRUE)), self::NOW));
    $this->assertTrue(PolicyEvaluator::holds([$png], Action::Modify, $bob, $existing->withChange(new ResourceChange(typesUnknown: TRUE)), self::NOW));
  }

  /**
   * Tests the end a policy's time constraints give it.
   */
  public function testNotAfter(): void {
    $this->assertNull(self::policy()->notAfter());
    $policy = self::policy(constraints: [
      new Constraint('dateTime', 'gteq', '2026-01-01T00:00:00Z'),
      new Constraint('dateTime', 'lteq', '2026-06-01T00:00:00+02:00'),
      new Constraint('dateTime', 'lt', '2026-07-01T00:00:00Z'),
    ]);
    $this->assertSame(strtotime('2026-05-31T22:00:00Z'), $policy->notAfter());
  }

}
