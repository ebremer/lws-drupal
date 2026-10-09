<?php

declare(strict_types=1);

namespace Drupal\lws_agent_users;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\externalauth\AuthmapInterface;
use Drupal\externalauth\Exception\ExternalAuthRegisterException;
use Drupal\externalauth\ExternalAuthInterface;
use Drupal\lws\Agent\AgentUser;
use Drupal\lws\Agent\AgentUsersInterface;
use Drupal\lws\Agent\RequestingAgent;
use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws\Routing\LwsUrlParser;
use Drupal\lws_identity\AgentDocuments;
use Drupal\lws_identity\AgentUris;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * The Drupal users LWS agents act as (DESIGN.md §7.5).
 *
 * An agent acts as a user:
 *
 * - when its URI is linked to the user. The link is the user's lws_agent_uri
 *   field, and its entry in the externalauth authmap (provider "lws"), which
 *   finds it: "sha256:" and the URI's SHA-256 in hex, as agent URIs can be
 *   longer than the 128 characters of an authname;
 * - with lws_identity, when it is the user's own agent URI and the user has
 *   an agent identity (whether or not the user is blocked, so that blocking
 *   bars it);
 * - in provision mode, when its first valid token created an account for it.
 *
 * What the user makes of the agent (RequestingAgent::withStanding()): the
 * user's roles are groups, {prefix}/roles/{role}, that access policies may
 * name as assignees; a blocked user bars its agent; and a user who may bypass
 * LWS access policies makes its agent a controller of every storage. All of
 * it is read on every request, so a change takes effect on the next.
 */
final class AgentUsers implements AgentUsersInterface {

  /**
   * The externalauth provider of the links.
   */
  public const PROVIDER = 'lws';

  /**
   * The user field that holds the agent URI linked to the user.
   */
  public const URI_FIELD = 'lws_agent_uri';

  /**
   * The user field that marks an account made for an agent.
   */
  public const PROVISIONED_FIELD = 'lws_agent_provisioned';

  /**
   * The longest agent URI a user can be linked to, in bytes.
   */
  public const MAX_URI_BYTES = 2048;

  /**
   * The permission to bypass LWS access policies.
   */
  public const BYPASS = 'bypass lws access policy';

  /**
   * The segment under the LWS prefix of role URIs (LwsUrlParser::RESERVED).
   */
  public const ROLES_SEGMENT = 'roles';

  /**
   * The flood event of accounts provisioned.
   */
  private const FLOOD = 'lws_agent_users.provision';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AuthmapInterface $authmap,
    private readonly ExternalAuthInterface $externalAuth,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LwsUrlGenerator $urls,
    private readonly LwsUrlParser $parser,
    private readonly FloodInterface $flood,
    private readonly LoggerInterface $logger,
    private readonly ?AgentUris $localAgents = NULL,
  ) {}

  /**
   * The authmap name of an agent URI.
   */
  public static function authname(string $agentUri): string {
    return 'sha256:' . hash('sha256', $agentUri);
  }

  /**
   * The URI that stands for a role in access policies.
   *
   * It is an identifier only: dereferenced, it is not found.
   */
  public function roleUri(string $role): string {
    return $this->urls->baseUrl() . $this->parser->prefix() . '/' . self::ROLES_SEGMENT . '/' . $role;
  }

  /**
   * Whether a URI is one of this site's agent URIs (lws_identity).
   *
   * Such an agent acts as its user without a link.
   */
  public function isLocalAgent(string $agentUri): bool {
    return $this->localAgents?->uuid($agentUri) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function find(RequestingAgent $agent, bool $provision = FALSE): ?AgentUser {
    if (!$agent->isAuthenticated()) {
      return NULL;
    }
    $user = $this->userOf((string) $agent->subject) ?? ($provision ? $this->provision($agent) : NULL);
    if ($user === NULL) {
      return NULL;
    }
    if ($user->isBlocked()) {
      return new AgentUser($agent->withStanding([], TRUE, FALSE), (int) $user->id(), NULL);
    }
    $standing = $agent->withStanding(
      array_values(array_map($this->roleUri(...), $user->getRoles())),
      FALSE,
      $user->hasPermission(self::BYPASS),
    );
    return new AgentUser($standing, (int) $user->id(), new AgentUserSession($standing, $user));
  }

  /**
   * The user an agent URI acts as, if any; blocked users included.
   */
  public function userOf(string $agentUri): ?UserInterface {
    $storage = $this->entityTypeManager->getStorage('user');
    $uuid = $this->localAgents?->uuid($agentUri);
    if ($uuid !== NULL) {
      $users = $storage->loadByProperties(['uuid' => $uuid]);
      $user = reset($users);
      return $user instanceof UserInterface && !$user->isAnonymous() && $user->hasPermission(AgentDocuments::PERMISSION) ? $user : NULL;
    }
    $uid = $this->authmap->getUid(self::authname($agentUri), self::PROVIDER);
    $user = is_bool($uid) ? NULL : $storage->load((int) $uid);
    // The field confirms the link, digest and all.
    return $user instanceof UserInterface && $user->get(self::URI_FIELD)->value === $agentUri ? $user : NULL;
  }

  /**
   * Creates an account for an agent's first valid token, where the site does.
   *
   * The account has no password and no e-mail address, cannot log in
   * (LwsAgentUsersHooks::refuseProvisioned()), and is named after the hash
   * of the agent URI. Two first requests at once make one account: the
   * second finds the first's.
   */
  private function provision(RequestingAgent $agent): ?UserInterface {
    $settings = $this->configFactory->get('lws_agent_users.settings');
    $uri = (string) $agent->subject;
    if ($settings->get('mode') !== 'provision' || strlen($uri) > self::MAX_URI_BYTES || $this->isLocalAgent($uri)) {
      return NULL;
    }
    $issuers = (array) $settings->get('provision.issuers');
    if ($issuers !== [] && !in_array($agent->issuer, $issuers, TRUE)) {
      return NULL;
    }
    $prefixes = (array) $settings->get('provision.prefixes');
    if ($prefixes !== [] && array_filter($prefixes, static fn (string $prefix): bool => str_starts_with($uri, $prefix)) === []) {
      return NULL;
    }
    $limit = (int) $settings->get('provision.per_hour');
    if (!$this->flood->isAllowed(self::FLOOD, $limit, 3600, 'site')) {
      $this->logger->warning('No account for agent @agent: @limit accounts were made for agents in the last hour.', [
        '@agent' => $uri,
        '@limit' => $limit,
      ]);
      return NULL;
    }
    $this->flood->register(self::FLOOD, 3600, 'site');
    try {
      $user = $this->externalAuth->register(self::authname($uri), self::PROVIDER, [
        'name' => 'lws-agent-' . substr(hash('sha256', $uri), 0, 24),
        'roles' => $this->provisionedRoles((array) $settings->get('provision.roles')),
        self::URI_FIELD => $uri,
        self::PROVISIONED_FIELD => TRUE,
      ]);
    }
    catch (ExternalAuthRegisterException | EntityStorageException | IntegrityConstraintViolationException $e) {
      $user = $this->userOf($uri);
      if ($user === NULL) {
        $this->logger->error('No account for agent @agent: @message', ['@agent' => $uri, '@message' => $e->getMessage()]);
      }
      return $user;
    }
    $this->logger->notice('Made account @name for agent @agent.', ['@name' => $user->getAccountName(), '@agent' => $uri]);
    return $user;
  }

  /**
   * The roles provisioned accounts get.
   *
   * Those configured that exist, and are not administrator roles.
   *
   * @param array<mixed> $configured
   *   The configured role IDs.
   *
   * @return list<string>
   *   The role IDs.
   */
  private function provisionedRoles(array $configured): array {
    $roles = [];
    foreach ($this->entityTypeManager->getStorage('user_role')->loadMultiple(array_map('strval', $configured)) as $id => $role) {
      if (!$role->isAdmin() && !in_array($id, [RoleInterface::ANONYMOUS_ID, RoleInterface::AUTHENTICATED_ID], TRUE)) {
        $roles[] = (string) $id;
      }
    }
    return $roles;
  }

}
