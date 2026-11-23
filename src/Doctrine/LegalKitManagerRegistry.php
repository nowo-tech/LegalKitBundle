<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Doctrine;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use Nowo\LegalKitBundle\DependencyInjection\Compiler\EntityManagerMappingPass;

use function str_starts_with;
use function strlen;
use function strrpos;
use function substr;

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
    /** Namespace marker of the Doctrine ORM proxy classes. */
    private const string PROXY_MARKER = '__CG__';

    /**
     * @param ManagerRegistry $registry The application's Doctrine registry
     * @param LegalKitEntityManager $entityManager The bundle's private manager
     */
    public function __construct(
        private ManagerRegistry $registry,
        private LegalKitEntityManager $entityManager,
    ) {
    }

    /**
     * Name of the default connection, from the wrapped registry.
     */
    public function getDefaultConnectionName(): string
    {
        return $this->registry->getDefaultConnectionName();
    }

    /**
     * Returns a connection of the wrapped registry.
     */
    public function getConnection(?string $name = null): object
    {
        return $this->registry->getConnection($name);
    }

    /**
     * Connections of the wrapped registry.
     *
     * @return array<string, object>
     */
    public function getConnections(): array
    {
        return $this->registry->getConnections();
    }

    /**
     * Names of the connections of the wrapped registry.
     *
     * @return array<string, string>
     */
    public function getConnectionNames(): array
    {
        return $this->registry->getConnectionNames();
    }

    /**
     * Name of the default manager, from the wrapped registry.
     */
    public function getDefaultManagerName(): string
    {
        return $this->registry->getDefaultManagerName();
    }

    /**
     * Returns a manager of the wrapped registry.
     */
    public function getManager(?string $name = null): ObjectManager
    {
        return $this->registry->getManager($name);
    }

    /**
     * Managers of the wrapped registry.
     *
     * @return array<string, ObjectManager>
     */
    public function getManagers(): array
    {
        return $this->registry->getManagers();
    }

    /**
     * Resets a manager of the wrapped registry.
     */
    public function resetManager(?string $name = null): ObjectManager
    {
        return $this->registry->resetManager($name);
    }

    /**
     * Names of the managers of the wrapped registry.
     *
     * @return array<string, string>
     */
    public function getManagerNames(): array
    {
        return $this->registry->getManagerNames();
    }

    /**
     * Returns the bundle manager for the bundle entities, the wrapped registry's repository for the rest.
     */
    public function getRepository(string $persistentObject, ?string $persistentManagerName = null): ObjectRepository
    {
        if ($persistentManagerName === null && self::isBundleEntity($persistentObject)) {
            return $this->entityManager->getRepository($persistentObject);
        }

        return $this->registry->getRepository($persistentObject, $persistentManagerName);
    }

    /**
     * Returns the bundle manager for a bundle entity class, the wrapped registry's manager for the rest.
     */
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
        // A Doctrine proxy class (`Proxies\\__CG__\\Nowo\\LegalKitBundle\\...`) is its entity class.
        $class = self::realClass($class);
        foreach ([EntityManagerMappingPass::ENTITY_NAMESPACE, EntityManagerMappingPass::VERSIONING_NAMESPACE, EntityManagerMappingPass::ACCEPTANCE_NAMESPACE] as $namespace) {
            if (str_starts_with($class, $namespace . '\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The entity class of a Doctrine proxy class name (the `__CG__` marker of the ORM proxies), else the name itself.
     *
     * @param string $class A class name
     */
    public static function realClass(string $class): string
    {
        $position = strrpos($class, '\\' . self::PROXY_MARKER . '\\');

        return $position === false ? $class : substr($class, $position + strlen(self::PROXY_MARKER) + 2);
    }
}
