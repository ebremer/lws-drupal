<?php

declare(strict_types=1);

namespace Drupal\lws_storage\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\lws\Access\AccessDecisionInterface;
use Drupal\lws\Access\Action;
use Drupal\lws\Access\ResourceChange;
use Drupal\lws\Access\ResourceContext;
use Drupal\lws\Agent\Authentication;
use Drupal\lws\Routing\LwsArea;
use Drupal\lws\Routing\LwsTarget;
use Drupal\lws_storage\Entity\LwsStorageInterface;
use Drupal\lws_storage\Http\WriteRequests;
use Drupal\lws_storage\Linkset\Linksets;
use Drupal\lws_storage\ResourceLinks;
use Drupal\lws_storage\ResourceRepository;
use Ebremer\Lws\ResourceType;
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
 * The decision depends on the URL, and on the format and types of the
 * resource if it exists, which policies may be limited to: a resource that
 * does not exist is refused as one of another format or type would be. A
 * write is also judged on what it would make of the resource: the format and
 * types of the resource a POST creates, and the new ones a PUT or PATCH sets.
 */
final class LwsAccessCheck implements AccessInterface {

  /**
   * A media type, as a Content-Type header starts with it.
   */
  private const MEDIA_TYPE = '/^[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*\/[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*$/';

  public function __construct(
    private readonly AccessDecisionInterface $decisions,
    private readonly ResourceLinks $links,
    private readonly ResourceRepository $resources,
    private readonly Linksets $linksets,
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
    $context = $storage instanceof LwsStorageInterface && $target instanceof LwsTarget && $action !== NULL
      ? $this->context($storage, $target, $action, $request)
      : NULL;
    if ($requirement !== 'resource' || $context === NULL) {
      return self::result(FALSE, 'The route has no LWS access requirement this check understands.');
    }
    return self::result($this->decisions->decide($authentication->agent, $action, $context)->isPermitted());
  }

  /**
   * What the policy decision point knows about the target.
   */
  private function context(LwsStorageInterface $storage, LwsTarget $target, Action $action, Request $request): ?ResourceContext {
    if ($target->area === LwsArea::Resource) {
      $resource = $this->resources->findByTarget($storage, $target);
      $context = $resource === NULL
        ? $this->links->context($storage, $target->segments, $target->container)
        : $this->links->contextOf($storage, $resource);
      $change = match ($action) {
        Action::Create => $this->created($request),
        Action::Modify => $this->modified($request, $context),
        default => NULL,
      };
      return $change === NULL ? $context : $context->withChange($change);
    }
    if ($target->area === LwsArea::Meta) {
      $resource = $this->resources->findByUuid($storage, (string) $target->metaId);
      // A missing linkset is judged as the storage root: those who may read
      // that learn it is missing, and nobody else.
      $context = $resource === NULL
        ? $this->links->context($storage, ['root'], TRUE)
        : $this->links->contextOf($storage, $resource);
      // What a linkset write does to the types is known only once it is done.
      return $action === Action::Modify ? $context->withChange(new ResourceChange(typesUnknown: TRUE)) : $context;
    }
    return NULL;
  }

  /**
   * The resource a POST would create.
   */
  private function created(Request $request): ResourceChange {
    $links = WriteRequests::links($request);
    $container = WriteRequests::createsContainer($links);
    $class = $container ? ResourceType::CONTAINER : ResourceType::DATA_RESOURCE;
    return new ResourceChange(
      $container ? NULL : self::mediaType($request) ?? 'application/octet-stream',
      array_values(array_unique([$class, ...$this->linksets->fromLinkHeaders($links)->types])),
    );
  }

  /**
   * What a PUT or PATCH would change.
   *
   * That is the format, and with "Prefer: set-linkset" the types.
   */
  private function modified(Request $request, ResourceContext $resource): ?ResourceChange {
    // A PATCH's Content-Type is that of the patch, not of the resource.
    $mediaType = $request->isMethod('PUT') ? self::mediaType($request) : NULL;
    $types = NULL;
    if (WriteRequests::prefersSetLinkset($request)) {
      $class = $resource->container ? ResourceType::CONTAINER : ResourceType::DATA_RESOURCE;
      $declared = $this->linksets->fromLinkHeaders(WriteRequests::links($request))->types;
      $types = array_values(array_unique([$class, ...$declared]));
    }
    return $mediaType === NULL && $types === NULL ? NULL : new ResourceChange($mediaType, $types);
  }

  /**
   * The media type of the request body, if it names a valid one.
   *
   * An invalid one is the controller's to refuse.
   */
  private static function mediaType(Request $request): ?string {
    $type = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));
    return preg_match(self::MEDIA_TYPE, $type) === 1 ? $type : NULL;
  }

  /**
   * An access result that is never cached: decisions depend on the token.
   */
  private static function result(bool $allowed, string $reason = ''): AccessResultInterface {
    $result = $allowed ? AccessResult::allowed() : AccessResult::forbidden($reason);
    return $result->setCacheMaxAge(0);
  }

}
