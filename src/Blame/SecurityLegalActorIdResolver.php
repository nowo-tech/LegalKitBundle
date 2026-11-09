<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Blame;

use Stringable;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_int;
use function is_numeric;
use function is_string;
use function mb_strlen;
use function method_exists;
use function trim;

/**
 * Default actor id resolver: the signed-in user's `getId()` (int, string or Stringable such as a Uuid) when it has
 * one, its user identifier otherwise. The user does not have to be a Doctrine entity.
 *
 * Ids and identifiers of every class share one column, so with an empty `doctrine.accepted_user_classes` the default
 * suits one identity domain only: a host with several user classes / providers (Admin#5 next to Customer#5) lists the
 * class whose ids it records, or aliases {@see LegalActorIdResolverInterface} to a resolver that prefixes the domain
 * (`admin:5`). Actors of other classes are then recorded as unknown (null).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class SecurityLegalActorIdResolver implements LegalActorIdResolverInterface
{
    /**
     * @param ?TokenStorageInterface $tokenStorage Security token storage (null without SecurityBundle)
     * @param list<string> $acceptedUserClasses `doctrine.accepted_user_classes`: only actors of these classes get an
     *                                          id (empty = any actor: one identity domain)
     */
    public function __construct(
        private ?TokenStorageInterface $tokenStorage = null,
        private array $acceptedUserClasses = [],
    ) {
    }

    /**
     * The signed-in user's actor id; null without a token or a user.
     *
     * @return ?string The actor id
     */
    public function currentActorId(): ?string
    {
        $user = $this->tokenStorage?->getToken()?->getUser();

        return $user instanceof UserInterface ? $this->actorIdFor($user) : null;
    }

    /**
     * Whether the token holds a user (an actor that may still be recorded as unknown).
     */
    public function hasCurrentActor(): bool
    {
        return $this->tokenStorage?->getToken()?->getUser() instanceof UserInterface;
    }

    /**
     * `getId()` (int, string, Stringable) when not empty, else the user identifier of a security user; null when longer
     * than {@see LegalActorIdResolverInterface::MAX_LENGTH}, not an actor, of a class not listed in
     * `doctrine.accepted_user_classes` (when the list is set), or when `getId()` is 0, negative or blank.
     *
     * @return ?string The actor id
     */
    public function actorIdFor(object $actor): ?string
    {
        if (!$this->isAccepted($actor)) {
            return null;
        }

        $id = null;
        if (method_exists($actor, 'getId')) {
            $value = $actor->getId();
            $id    = is_int($value) ? (string) $value : (is_string($value) || $value instanceof Stringable ? (string) $value : null);
            // JX-L1: an id of 0, a negative id or a blank id identifies nobody; it never falls back to the identifier.
            if ($id !== null && $id !== '' && (trim($id) === '' || (is_numeric($id) && (float) $id <= 0))) {
                return null;
            }
        }
        if (($id === null || $id === '') && $actor instanceof UserInterface) {
            $id = $actor->getUserIdentifier();
        }

        return $id === null || $id === '' || mb_strlen($id) > self::MAX_LENGTH ? null : $id;
    }

    /**
     * Whether the actor's class is accepted (`doctrine.accepted_user_classes`; any class when the list is empty).
     */
    private function isAccepted(object $actor): bool
    {
        if ($this->acceptedUserClasses === []) {
            return true;
        }
        foreach ($this->acceptedUserClasses as $class) {
            if ($actor instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
