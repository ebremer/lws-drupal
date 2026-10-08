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
use Drupal\lws\Routing\LwsUrlGenerator;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\StorageRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Checks access to LWS routes: the "_lws_access" requirement.
 *
 * - "public": anyone, such as the storage description, which clients read
 *   before they have a token;
 * - "resource": as the policy decision point decides for the agent, the
 *   method's action and the target resource.
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
    private readonly StorageRegistry $storages,
    private readonly LwsUrlGenerator $urls,
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
    if ($requirement !== 'resource' || !$storage instanceof LwsStorageInterface || !$target instanceof LwsTarget || $target->area !== LwsArea::Resource || $action === NULL) {
      return self::result(FALSE, 'The route has no LWS access requirement this check understands.');
    }
    $decision = $this->decisions->decide($authentication->agent, $action, $this->context($storage, $target));
    return self::result($decision->isPermitted());
  }

  /**
   * What the policy decision point knows about the target resource.
   */
  private function context(LwsStorageInterface $storage, LwsTarget $target): ResourceContext {
    $slug = $storage->getSlug();
    $ancestors = [];
    for ($i = 1; $i < count($target->segments); $i++) {
      $ancestors[] = $this->urls->resourceUri($slug, array_slice($target->segments, 0, $i), TRUE);
    }
    return new ResourceContext(
      $this->storages->ref($storage),
      $this->urls->resourceUri($slug, $target->segments, $target->container),
      $ancestors,
      $target->container,
    );
  }

  /**
   * An access result that is never cached: decisions depend on the token.
   */
  private static function result(bool $allowed, string $reason = ''): AccessResultInterface {
    $result = $allowed ? AccessResult::allowed() : AccessResult::forbidden($reason);
    return $result->setCacheMaxAge(0);
  }

}
