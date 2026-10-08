<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\lws\Access\AccessDecisionInterface;
use Drupal\lws\Access\Action;
use Drupal\lws\Access\ResourceContext;
use Drupal\lws\Agent\Authentication;
use Drupal\lws\Routing\LwsArea;
use Drupal\lws\Routing\LwsTarget;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\ResourceLinks;
use Drupal\lws_storage\ResourceRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Checks access to LWS routes: the "_lws_access" requirement.
 *
 * - "public": anyone, such as the storage description, which clients read
 *   before they have a token;
 * - "resource": as the policy decision point decides for the agent, the
 *   method's action and the target resource. POST is checked as Create on
 *   the target container. A linkset is checked as its resource.
 *
 * Either way a request whose token was rejected is refused, even where no
 * token is needed (LWS Core §5.2.4.2). Refusals become 401 or 403 in
 * LwsExceptionSubscriber, depending on whether the agent was authenticated.
 *
 * The decision depends only on the URL, never on whether the resource exists,
 * so that an agent who may not read a container cannot learn what is in it.
 */
final class LwsAccessCheck implements AccessInterface {

  public function __construct(
    private readonly AccessDecisionInterface $decisions,
    private readonly ResourceLinks $links,
    private readonly ResourceRepository $resources,
  ) {}

  /**
   * Checks access.
   */
  public function access(Route $route, Request $request): AccessResultInterface {
    $authentication = Authentication::fromRequest($request);
    if ($authentication->isFailed()) {
      return self::result(FALSE, (string) $authentication->errorDescription);
    }
    $requirement = $route->getRequirement('_lws_access');
    if ($requirement === 'public') {
      return self::result(TRUE);
    }

    $storage = $request->attributes->get('lws_storage');
    $target = $request->attributes->get('lws_target');
    $action = Action::forMethod($request->getMethod());
    $context = $storage instanceof LwsStorageInterface && $target instanceof LwsTarget ? $this->context($storage, $target) : NULL;
    if ($requirement !== 'resource' || $context === NULL || $action === NULL) {
      return self::result(FALSE, 'The route has no LWS access requirement this check understands.');
    }
    return self::result($this->decisions->decide($authentication->agent, $action, $context)->isPermitted());
  }

  /**
   * What the policy decision point knows about the target.
   */
  private function context(LwsStorageInterface $storage, LwsTarget $target): ?ResourceContext {
    if ($target->area === LwsArea::Resource) {
      return $this->links->context($storage, $target->segments, $target->container);
    }
    if ($target->area === LwsArea::Meta) {
      $resource = $this->resources->findByUuid($storage, (string) $target->metaId);
      // A missing linkset is judged as the storage root: those who may read
      // that learn it is missing, and nobody else.
      return $resource === NULL
        ? $this->links->context($storage, ['root'], TRUE)
        : $this->links->contextOf($storage, $resource);
    }
    return NULL;
  }

  /**
   * An access result that is never cached: decisions depend on the token.
   */
  private static function result(bool $allowed, string $reason = ''): AccessResultInterface {
    $result = $allowed ? AccessResult::allowed() : AccessResult::forbidden($reason);
    return $result->setCacheMaxAge(0);
  }

}
