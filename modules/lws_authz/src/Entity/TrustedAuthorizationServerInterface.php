<?php

declare(strict_types=1);

namespace Drupal\lws_authz\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\lws_authz\AuthorizationServerInterface;

/**
 * An external authorization server whose access tokens storages accept.
 *
 * Its getJwks() gives its pinned keys, if it has any.
 */
interface TrustedAuthorizationServerInterface extends ConfigEntityInterface, AuthorizationServerInterface {}
