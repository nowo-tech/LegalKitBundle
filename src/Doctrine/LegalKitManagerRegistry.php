<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Doctrine;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use Nowo\LegalKitBundle\DependencyInjection\Compiler\EntityManagerMappingPass;

use function str_starts_with;

/**
 * The Doctrine registry the bundle repositories see (1.12, rule 6, private entity manager only): the bundle entities
 * resolve to {@see LegalKitEntityManager}, everything else to the application's registry unchanged. The repositories
 * (`ServiceEntityRepository`) therefore load and write the legal rows through the private unit of work, the same one
 * the bundle services flush.
 *
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LegalKitManagerRegistry implements ManagerRegistry
{
    /**
     * @param ManagerRegistry $registry The application's Doctrine registry
     * @param LegalKitEntityManager $entityManager The bundle's private manager
     */
    public function __construct(
        private ManagerRegistry $registry,
        private LegalKitEntityManager $entityManager,
    ) {
    }

    public function getDefaultConnectionName(): string
    {
        return $this->registry->getDefaultConnectionName();
    }

    public function getConnection(?string $name = null): object
    {
        return $this->registry->getConnection($name);
    }

    public function getConnections(): array
    {
        return $this->registry->getConnections();
    }

    public function getConnectionNames(): array
    {
        return $this->registry->getConnectionNames();
    }

    public function getDefaultManagerName(): string
    {
        return $this->registry->getDefaultManagerName();
    }

    public function getManager(?string $name = null): ObjectManager
    {
        return $this->registry->getManager($name);
    }

    public function getManagers(): array
    {
        return $this->registry->getManagers();
    }

    public function resetManager(?string $name = null): ObjectManager
    {
        return $this->registry->resetManager($name);
    }

    public function getManagerNames(): array
    {
        return $this->registry->getManagerNames();
    }

    public function getRepository(string $persistentObject, ?string $persistentManagerName = null): ObjectRepository
    {
        if ($persistentManagerName === null && self::isBundleEntity($persistentObject)) {
            return $this->entityManager->getRepository($persistentObject);
        }

        return $this->registry->getRepository($persistentObject, $persistentManagerName);
    }

    public function getManagerForClass(string $class): ?ObjectManager
    {
        if (self::isBundleEntity($class)) {
            return $this->entityManager;
        }

        return $this->registry->getManagerForClass($class);
    }

    /**
     * Whether a class is one of the bundle entities (legal document, page version, acceptance).
     */
    public static function isBundleEntity(string $class): bool
    {
        foreach ([EntityManagerMappingPass::ENTITY_NAMESPACE, EntityManagerMappingPass::VERSIONING_NAMESPACE, EntityManagerMappingPass::ACCEPTANCE_NAMESPACE] as $namespace) {
            if (str_starts_with($class, $namespace . '\\')) {
                return true;
            }
        }

        return false;
    }
}
