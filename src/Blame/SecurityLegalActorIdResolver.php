<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Blame;

use Stringable;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_int;
use function is_string;
use function mb_strlen;
use function method_exists;

/**
 * Default actor id resolver: the signed-in user's `getId()` (int, string or Stringable such as a Uuid) when it has
 * one, its user identifier otherwise. The user does not have to be a Doctrine entity.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class SecurityLegalActorIdResolver implements LegalActorIdResolverInterface
{
    /**
     * @param ?TokenStorageInterface $tokenStorage Security token storage (null without SecurityBundle)
     */
    public function __construct(
        private ?TokenStorageInterface $tokenStorage = null,
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
     * `getId()` (int, string, Stringable) when not empty, else the user identifier of a security user; null when longer
     * than {@see LegalActorIdResolverInterface::MAX_LENGTH} or not an actor.
     *
     * @return ?string The actor id
     */
    public function actorIdFor(object $actor): ?string
    {
        $id = null;
        if (method_exists($actor, 'getId')) {
            $value = $actor->getId();
            $id    = is_int($value) ? (string) $value : (is_string($value) || $value instanceof Stringable ? (string) $value : null);
        }
        if (($id === null || $id === '') && $actor instanceof UserInterface) {
            $id = $actor->getUserIdentifier();
        }

        return $id === null || $id === '' || mb_strlen($id) > self::MAX_LENGTH ? null : $id;
    }
}
