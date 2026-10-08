<?php

declare(strict_types=1);

namespace Drupal\lws\Access;

/**
 * An operation on a resource, as the access profile names them (§11.3.2).
 *
 * Control, which covers managing access grants, is not one of the profile's
 * actions: only storage controllers have it.
 */
enum Action: string {

  case Read = 'read';
  case Create = 'create';
  case Modify = 'modify';
  case Delete = 'delete';
  case Control = 'control';

  /**
   * The action an HTTP method performs on its target, if any.
   *
   * POST creates a resource in the target container, so its action is
   * checked against that container.
   */
  public static function forMethod(string $method): ?self {
    return match (strtoupper($method)) {
      'GET', 'HEAD', 'QUERY' => self::Read,
      'POST' => self::Create,
      'PUT', 'PATCH' => self::Modify,
      'DELETE' => self::Delete,
      default => NULL,
    };
  }

}
