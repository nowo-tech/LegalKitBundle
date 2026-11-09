<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Support\Blame;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Security user with an id (audit JX-L1 tests: two identity domains with overlapping ids).
 */
final readonly class TestCustomerUser implements UserInterface
{
    /**
     * @param non-empty-string $identifier
     */
    public function __construct(
        private int|string $id,
        private string $identifier = 'fallback',
    ) {
    }

    public function getId(): int|string
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
    {
        return $this->identifier;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }
}
