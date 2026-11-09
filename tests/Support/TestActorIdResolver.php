<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Support;

use Nowo\LegalKitBundle\Blame\LegalActorIdResolverInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Host actor id resolver of a hexagonal app: the signed-in user is a plain security user (no Doctrine entity) and the
 * actor id is the public id of the account aggregate ("acc-<identifier>").
 */
final readonly class TestActorIdResolver implements LegalActorIdResolverInterface
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    public function currentActorId(): ?string
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return $user !== null ? 'acc-' . $user->getUserIdentifier() : null;
    }

    public function actorIdFor(object $actor): string
    {
        return 'acc-explicit';
    }
}
