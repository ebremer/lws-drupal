<?php

declare(strict_types=1);

namespace Drupal\lws_agent_users\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\externalauth\AuthmapInterface;
use Drupal\lws_agent_users\AgentUsers;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the agent URI of a user: absolute, and no other user's.
 */
final class AgentUriConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  use AutowireTrait;

  /**
   * An absolute URI: a scheme, and visible ASCII without spaces.
   */
  private const URI = '/^[A-Za-z][A-Za-z0-9+.\-]*:[\x21-\x7E]+$/';

  public function __construct(
    private readonly AgentUsers $users,
    private readonly AuthmapInterface $authmap,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$value instanceof FieldItemListInterface || !$constraint instanceof AgentUriConstraint) {
      return;
    }
    $uri = (string) $value->value;
    if ($uri === '') {
      return;
    }
    if (preg_match(self::URI, $uri) !== 1) {
      $this->context->addViolation($constraint->notUri);
      return;
    }
    if ($this->users->isLocalAgent($uri)) {
      $this->context->addViolation($constraint->local);
      return;
    }
    $uid = $this->authmap->getUid(AgentUsers::authname($uri), AgentUsers::PROVIDER);
    if (!is_bool($uid) && (int) $uid !== (int) $value->getEntity()->id()) {
      $other = $this->entityTypeManager->getStorage('user')->load((int) $uid);
      $this->context->addViolation($constraint->taken, ['%user' => $other?->label() ?? (string) $uid]);
    }
  }

}
