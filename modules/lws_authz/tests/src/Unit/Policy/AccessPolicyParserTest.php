<?php

declare(strict_types=1);

namespace Drupal\Tests\lws_authz\Unit\Policy;

use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws\Routing\LwsUrlParser;
use Drupal\lws\Storage\StorageRef;
use Drupal\lws_authz\Policy\AccessPolicy;
use Drupal\lws_authz\Policy\AccessPolicyParser;
use Drupal\lws_authz\Policy\InvalidPolicyException;
use Drupal\Tests\UnitTestCase;
use Ebremer\Lws\Json\Json;
use Ebremer\Lws\ResourceType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests reading access policies from their JSON form.
 */
#[CoversClass(AccessPolicyParser::class)]
#[Group('lws')]
final class AccessPolicyParserTest extends UnitTestCase {

  private const STORAGE = 'https://storage.example/lws/alice/';

  /**
   * The parser.
   */
  private AccessPolicyParser $parser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $config = $this->getConfigFactoryStub([
      'lws.settings' => ['prefix' => '/lws', 'base_url' => 'https://storage.example'],
    ]);
    $urls = new LwsUrlParser($config);
    $this->parser = new AccessPolicyParser($urls, new LwsUrlGenerator($urls, $config, new RequestStack()));
  }

  /**
   * Reads a policy of alice's storage.
   *
   * @param array<string, mixed> $json
   *   Members to add or replace; NULL removes one.
   */
  private function parse(array $json): AccessPolicy {
    $json += [
      'type' => ['AccessPolicy'],
      'action' => ['read'],
      'assignee' => 'https://id.example/bob',
      'target' => ['type' => 'StorageResource', 'value' => [self::STORAGE . 'root/shared/']],
    ];
    $json = array_filter($json, static fn ($value) => $value !== NULL);
    return $this->parser->parse($json, new StorageRef(1, 'alice', self::STORAGE, []));
  }

  /**
   * Tests a full policy, and its round trip.
   */
  public function testParse(): void {
    $json = [
      '@context' => ['https://www.w3.org/ns/lws/v1'],
      'type' => 'AccessPolicy',
      'action' => ['delete', 'read', 'read'],
      'target' => [
        'type' => 'https://www.w3.org/ns/lws#Container',
        'value' => [self::STORAGE . 'root/a%7eb/', self::STORAGE . 'root/shared', self::STORAGE],
      ],
      'constraint' => [
        ['leftOperand' => 'format', 'operator' => 'isAnyOf', 'rightOperand' => ['Image/PNG']],
        ['leftOperand' => 'type', 'operator' => 'eq', 'rightOperand' => 'DataResource'],
        [
          'leftOperand' => 'dateTime',
          'operator' => 'lteq',
          'rightOperand' => ['@value' => '2026-12-31T23:59:59Z', '@type' => 'xsd:dateTime'],
        ],
      ],
    ];
    $policy = $this->parse($json);
    // In the profile's order, once each.
    $this->assertSame(['read', 'delete'], $policy->actions);
    $this->assertSame(ResourceType::CONTAINER, $policy->targetType);
    // Canonical URIs: unreserved characters decoded.
    $this->assertSame([self::STORAGE . 'root/a~b/', self::STORAGE . 'root/shared', self::STORAGE], $policy->targetValues);
    $this->assertSame('image/png', $policy->constraints[0]->rightOperand[0] ?? NULL);
    $this->assertSame(ResourceType::DATA_RESOURCE, $policy->constraints[1]->rightOperand);
    $this->assertSame(strtotime('2026-12-31T23:59:59Z'), $policy->notAfter());

    $again = $this->parser->parse(Json::decode(Json::encode($policy->toJson())), new StorageRef(1, 'alice', self::STORAGE, []));
    $this->assertEquals($policy, $again);
    $this->assertSame('Container', $policy->toJson()['target']['type']);
  }

  /**
   * Tests what makes a policy invalid.
   *
   * @param array<string, mixed> $json
   *   Members to add or replace; NULL removes one.
   * @param string $message
   *   A fragment of the message.
   */
  #[DataProvider('invalidProvider')]
  public function testInvalid(array $json, string $message): void {
    $this->expectException(InvalidPolicyException::class);
    $this->expectExceptionMessage($message);
    $this->parse($json);
  }

  /**
   * Data provider for testInvalid().
   *
   * @return array<string, array{array<string, mixed>, string}>
   *   The members and a fragment of the message.
   */
  public static function invalidProvider(): array {
    $root = self::STORAGE . 'root/';
    $constraint = static fn (string $left, string $operator, mixed $right): array => [
      'constraint' => [['leftOperand' => $left, 'operator' => $operator, 'rightOperand' => $right]],
    ];
    $linkset = self::STORAGE . 'meta/0e0d8c2e-44b0-4d70-8f13-5a7c1b756117';
    return [
      'no type' => [['type' => NULL], 'AccessPolicy'],
      'another type' => [['type' => ['AccessGrant']], 'AccessPolicy'],
      'no actions' => [['action' => []], '"action"'],
      'an unknown action' => [['action' => ['read', 'control']], '"action"'],
      'an action as a string' => [['action' => 'read'], '"action"'],
      'no assignee' => [['assignee' => NULL], '"assignee"'],
      'an assignee that is no URI' => [['assignee' => 'bob'], '"assignee"'],
      'no target' => [['target' => NULL], '"target"'],
      'an unknown target type' => [
        ['target' => ['type' => 'Thing', 'value' => [$root]]],
        'StorageResource, Container or DataResource',
      ],
      'no target values' => [['target' => ['type' => 'StorageResource', 'value' => []]], '"value"'],
      'another storage' => [['target' => ['value' => ['https://storage.example/lws/bob/root/']]], 'not in the storage'],
      'another site' => [['target' => ['value' => ['https://other.example/lws/alice/root/']]], 'not in the storage'],
      'a linkset' => [['target' => ['value' => [$linkset]]], 'not in the storage'],
      'a query' => [['target' => ['value' => [$root . '?page=x']]], 'not in the storage'],
      'a dot segment' => [['target' => ['value' => [$root . '../']]], 'not in the storage'],
      'constraints not a list' => [['constraint' => ['leftOperand' => 'client']], '"constraint"'],
      'an unknown operand' => [$constraint('spatial', 'eq', 'x:y'), '"leftOperand"'],
      'an operator the operand lacks' => [$constraint('dateTime', 'neq', '2026-01-01T00:00:00Z'), '"operator"'],
      'a list for eq' => [$constraint('client', 'eq', ['https://app.example/id']), 'a string'],
      'a string for isAnyOf' => [$constraint('client', 'isAnyOf', 'https://app.example/id'), 'a list of strings'],
      'an empty list' => [$constraint('format', 'isAnyOf', []), 'a list of strings'],
      'a client that is no URI' => [$constraint('client', 'eq', 'app'), 'client constraint'],
      'a format that is no media type' => [$constraint('format', 'eq', 'png'), 'format constraint'],
      'a dateTime without a time zone' => [$constraint('dateTime', 'lteq', '2026-12-31T23:59:59'), 'time zone'],
    ];
  }

}
