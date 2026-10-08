<?php

declare(strict_types=1);

namespace Drupal\lws_authz\AccessService;

use Drupal\Component\EventDispatcher\Event;
use Drupal\lws\Storage\StorageRef;
use Drupal\lws_authz\Entity\LwsAccessRecordInterface;

/**
 * An access request or grant was created, or deleted (LWS Core §11.6).
 *
 * The core asks for notifications of a new request, to the storage's
 * controllers, and of a new grant, to the inbox of the request it answers or
 * its own. lws_notify delivers them; without it nothing listens.
 */
final class AccessRecordEvent extends Event {

  /**
   * A request or grant was created.
   */
  public const CREATED = 'lws_authz.access_record.created';

  /**
   * A request was cancelled or answered, or a grant revoked.
   */
  public const DELETED = 'lws_authz.access_record.deleted';

  /**
   * Constructs the event.
   *
   * @param \Drupal\lws_authz\Entity\LwsAccessRecordInterface $record
   *   The request or grant.
   * @param \Drupal\lws\Storage\StorageRef $storage
   *   Its storage.
   * @param string $uri
   *   Its URI.
   * @param \Drupal\lws_authz\Entity\LwsAccessRecordInterface|null $request
   *   For a grant made by approving a request, the request.
   */
  public function __construct(
    public readonly LwsAccessRecordInterface $record,
    public readonly StorageRef $storage,
    public readonly string $uri,
    public readonly ?LwsAccessRecordInterface $request = NULL,
  ) {}

}
