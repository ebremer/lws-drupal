<?php

declare(strict_types=1);

namespace Drupal\lws_authz\AccessService;

/**
 * A valid access request or access grant document (LWS Core §11.2).
 */
final class AccessDocument {

  /**
   * Constructs a document.
   *
   * @param string $kind
   *   The kind: "request" or "grant".
   * @param array<string, mixed> $document
   *   The document as submitted, with an @context and a type list that name
   *   what they must; without its "id".
   * @param list<\Drupal\lws_authz\Policy\AccessPolicy> $policies
   *   Its access policies, as they are enforced.
   * @param string|null $inbox
   *   Its inbox, if it names one.
   */
  public function __construct(
    public readonly string $kind,
    public readonly array $document,
    public readonly array $policies,
    public readonly ?string $inbox,
  ) {}

  /**
   * The assignees of its policies.
   *
   * @return list<string>
   *   The assignees, once each.
   */
  public function assignees(): array {
    return array_values(array_unique(array_map(static fn ($policy): string => $policy->assignee, $this->policies)));
  }

}
