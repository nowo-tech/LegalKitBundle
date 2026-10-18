<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Support\Entity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\Model\AuditUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Host user of the test kernel (table `user`, like the adopting apps): target of AuditKit's AuditUserInterface.
 */
#[ORM\Entity]
#[ORM\Table(name: '`user`')]
class User implements UserInterface, AuditUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param list<string> $roles
     */
    public function __construct(
        #[ORM\Column(length: 180)]
        private string $email,
        #[ORM\Column(type: 'json')]
        private array $roles = ['ROLE_ADMIN'],
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
    {
        return $this->email !== '' ? $this->email : 'anonymous';
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return $this->roles;
    }

    public function eraseCredentials(): void
    {
    }
}
