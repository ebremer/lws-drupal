<?php

declare(strict_types=1);

namespace Drupal\lws_identity\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\lws_identity\AgentDocuments;
use Drupal\lws_identity\AgentKeys;
use Drupal\lws_identity\AgentUris;
use Drupal\user\UserInterface;

/**
 * A user's agent: its URI, its keys and its OpenID Providers.
 */
final class AgentIdentityController implements ContainerInjectionInterface {

  use AutowireTrait;
  use StringTranslationTrait;

  public function __construct(
    private readonly AgentUris $uris,
    private readonly AgentKeys $keys,
    private readonly AgentDocuments $documents,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TimeInterface $time,
  ) {}

  /**
   * The page.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function page(UserInterface $user): array {
    $agent = $this->uris->uriOf($user);
    $build = [];
    if (AgentDocuments::hasAgent($user)) {
      $build['agent'] = [
        '#type' => 'item',
        '#title' => $this->t('Agent URI'),
        '#markup' => Link::fromTextAndUrl($agent, Url::fromUri($agent))->toString(),
      ];
      $build['agent_help'] = [
        '#markup' => '<p>' . $this->t('LWS storages know this user by this identifier. Its controlled identifier document names the keys below and the OpenID Providers this site names for its agents.') . '</p>',
      ];
    }
    else {
      $build['agent'] = [
        '#type' => 'item',
        '#title' => $this->t('No agent'),
        '#markup' => $this->t('This user has no agent: the account is blocked, or lacks the <em>Have an LWS agent identity</em> permission. Its keys are used once it has one.'),
      ];
    }

    $now = $this->time->getCurrentTime();
    $rows = [];
    foreach ($this->keys->keysOf((int) $user->id()) as $key) {
      $expires = $key->getExpires();
      $remove = Url::fromRoute('lws_identity.key_delete', [
        'user' => $user->id(),
        'lws_agent_key' => $key->id(),
      ]);
      $rows[] = [
        $key->label(),
        ['data' => ['#markup' => '<code>' . htmlspecialchars($key->getKeyId(), ENT_QUOTES) . '</code>']],
        AgentKeys::describe($key->getJwk()),
        $this->dateFormatter->format($key->getCreatedTime(), 'short'),
        match (TRUE) {
          $expires === NULL => $this->t('Never'),
          $expires <= $now => $this->t('Expired @date', ['@date' => $this->dateFormatter->format($expires, 'short')]),
          default => $this->dateFormatter->format($expires, 'short'),
        },
        Link::fromTextAndUrl($this->t('Remove'), $remove)->toString(),
      ];
    }
    $build['keys'] = [
      '#type' => 'table',
      '#caption' => $this->t('Keys'),
      '#header' => [
        $this->t('Label'),
        $this->t('Key ID'),
        $this->t('Type'),
        $this->t('Added'),
        $this->t('Expires'),
        $this->t('Operations'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No keys yet.'),
    ];
    $build['keys_help'] = [
      '#type' => 'item',
      '#markup' => $this->t('To authenticate with a key, sign a JWT whose <code>sub</code>, <code>iss</code> and <code>client_id</code> are the agent URI, whose <code>aud</code> is the authorization server, and whose <code>kid</code> header is the key ID, and exchange it for an access token there (LWS self-signed identity, lws10-authn-ssi-cid).'),
    ];

    $providers = $this->documents->openIdProviders();
    if ($providers !== []) {
      $build['openid'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('OpenID Providers'),
        '#items' => $providers,
      ];
    }

    (new CacheableMetadata())
      ->addCacheableDependency($user)
      ->addCacheTags(['lws_agent_key_list', 'config:lws_identity.settings', 'config:lws.settings'])
      ->setCacheMaxAge(0)
      ->applyTo($build);
    return $build;
  }

}
