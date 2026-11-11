<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Proxy\DefaultProxyClassNameResolver;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use Symfony\Contracts\Service\ResetInterface;

use function sprintf;

/**
 * The bundle's private entity manager (1.12, architecture bar rule 6), used when `doctrine.entity_manager` is not set.
 *
 * Before 1.12 the zero-config path wrote the legal entities through the application's default entity manager, so a
 * bundle write (`publishVersion()`, the seed command, an import with `flush: true`, the admin) flushed every change the
 * application had left pending. The bundle now writes through a **private** manager created on the connection,
 * configuration (mapping, metadata cache, repository factory) and event manager (AuditKit blame, table prefix, the
 * after-commit dispatcher) of the manager that maps {@see LegalDocument}, or on `doctrine.connection`. Its own unit of
 * work holds only bundle rows: a bundle flush never commits an application change, and a failed write closes only this
 * manager. The connection is shared, so a transaction the application opened around a bundle call includes the
 * bundle's rows (the after-commit dispatcher holds the events until the outermost commit).
 *
 * The object is a stable decorator (services and repositories keep it): the wrapped manager is resolved on every call
 * and created again when it was closed, when the source connection changed, after a rollback on its connection (the
 * identity map would describe rows that no longer exist; told by {@see AfterCommitMiddleware}) or after
 * {@see reset()}. The worker boundaries clear it ({@see LegalKitEntityManagerSubscriber}), and `kernel.reset` drops it.
 *
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class LegalKitEntityManager extends EntityManagerDecorator implements ResetInterface, TransactionObserverInterface
{
    private ?EntityManagerInterface $current = null;

    /** A transaction (or savepoint) holding rows of the private manager was rolled back: drop it at the next call. */
    private bool $stale = false;

    /**
     * @param ManagerRegistry $registry Doctrine registry (the source manager and connection are resolved per call)
     * @param string|null $connectionName `doctrine.connection` (null = the connection of the manager that maps the bundle entities)
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly ?string $connectionName = null,
    ) {
        // No wrapped manager yet: every read of $this->wrapped goes through __get() (resolved per call).
        unset($this->wrapped); // @phpstan-ignore unset.possiblyHookedProperty (final class; the parent property has no hook)
    }

    /**
     * The wrapped manager of {@see EntityManagerDecorator} (the only magic property).
     */
    public function __get(string $name): EntityManagerInterface
    {
        if ($name !== 'wrapped') {
            throw new LogicException(sprintf('Undefined property %s::$%s.', self::class, $name));
        }

        return $this->live();
    }

    /**
     * The open private manager, created (again) when needed.
     */
    public function live(): EntityManagerInterface
    {
        $source     = $this->source();
        $connection = $this->connection($source);
        $current    = $this->current;
        if ($current instanceof EntityManagerInterface && $current->isOpen() && $current->getConnection() === $connection
            // A rollback made the identity map stale; a manager in the middle of a write unit (persisted rows not
            // flushed yet) is kept until that unit flushes or the next boundary.
            && (!$this->stale || $current->getUnitOfWork()->hasPendingInsertions())) {
            return $current;
        }
        if ($current instanceof EntityManagerInterface && $current->isOpen()) {
            $current->clear();
        }
        // @igor-ignore - Bundle-owned flag of the private manager, reset when the manager is replaced.
        $this->stale = false;

        // DoctrineBundle's ContainerAwareEventManager extends EventManager; any other implementation gets a fresh one.
        $eventManager = $source->getEventManager();

        // @igor-ignore - Bundle-owned unit of work: cleared at every boundary, replaced when closed or when the connection changed.
        return $this->current = new EntityManager($connection, $source->getConfiguration(), $eventManager instanceof EventManager ? $eventManager : null);
    }

    /**
     * The private manager's handle on an entity of another manager (a user object given as an actor): a reference by
     * identifier, so a relation from a legal row to it does not look like a new entity to the private unit of work.
     * An entity of the private manager, an unmapped object or one without an identifier is returned unchanged.
     *
     * @template T of object
     *
     * @param T $entity The entity
     *
     * @return T The entity, or a reference of the private manager
     */
    public function adopt(object $entity): object
    {
        $manager = $this->live();
        $class   = DefaultProxyClassNameResolver::getClass($entity);
        if ($manager->contains($entity) || $manager->getMetadataFactory()->isTransient($class)) {
            return $entity;
        }
        $identifier = $manager->getClassMetadata($class)->getIdentifierValues($entity);
        if ($identifier === []) {
            return $entity;
        }

        /** @var T $reference */
        $reference = $manager->getReference($class, $identifier);

        return $reference;
    }

    /**
     * Whether a private manager exists right now (no side effect; tests and the boundary subscriber).
     */
    public function isCreated(): bool
    {
        return $this->current instanceof EntityManagerInterface;
    }

    /**
     * Detaches every row from the private manager (a unit-of-work boundary). Never touches an application manager and
     * never creates the private one.
     */
    public function clear(): void
    {
        if ($this->current instanceof EntityManagerInterface && $this->current->isOpen()) {
            $this->current->clear();
        }
    }

    /**
     * Drops the private manager when a failed write closed it (the start of a unit of work); the next call creates a
     * fresh one.
     */
    public function recover(): void
    {
        if ($this->current instanceof EntityManagerInterface && (!$this->current->isOpen() || $this->stale)) {
            // @igor-ignore - Boundary drop of the bundle's own closed manager (no state kept).
            $this->current = null;
            // @igor-ignore - Boundary drop of the bundle's own closed manager (no state kept).
            $this->stale = false;
        }
    }

    /**
     * Drops the private manager (`kernel.reset`); the next call creates a fresh one.
     */
    public function reset(): void
    {
        $this->clear();
        // @igor-ignore - kernel.reset of the bundle's own manager (no state kept).
        $this->current = null;
        // @igor-ignore - kernel.reset of the bundle's own manager (no state kept).
        $this->stale = false;
    }

    public function transactionStarted(object $connection): void
    {
    }

    /**
     * A rolled-back transaction may have held rows of the private manager: it is replaced at the next call (never
     * cleared here: the rollback may run inside the private manager's own failing flush).
     */
    public function transactionEnded(object $connection, bool $committed): void
    {
        if (!$committed && $this->current instanceof EntityManagerInterface) {
            // @igor-ignore - Bundle-owned flag of the private manager, cleared when the manager is replaced.
            $this->stale = true;
        }
    }

    public function savepointCreated(object $connection, string $name): void
    {
    }

    public function savepointReleased(object $connection, string $name): void
    {
    }

    /**
     * As {@see transactionEnded()} for a rolled-back savepoint (a nested transaction of the private manager or of the
     * application).
     */
    public function savepointRolledBack(object $connection, string $name): void
    {
        $this->transactionEnded($connection, false);
    }

    /**
     * The application's manager whose configuration and events the private one shares (never written through).
     */
    public function source(): EntityManagerInterface
    {
        $source = $this->registry->getManagerForClass(LegalDocument::class);
        if (!$source instanceof EntityManagerInterface) {
            throw new LogicException(sprintf('No Doctrine ORM entity manager maps %s: map the bundle entities (see docs/CONFIGURATION.md, "Doctrine").', LegalDocument::class));
        }

        return $source;
    }

    private function connection(EntityManagerInterface $source): Connection
    {
        if ($this->connectionName === null) {
            return $source->getConnection();
        }
        $connection = $this->registry->getConnection($this->connectionName);
        if (!$connection instanceof Connection) {
            throw new LogicException(sprintf('nowo_legal_kit.doctrine.connection "%s" is not a Doctrine DBAL connection.', $this->connectionName));
        }

        return $connection;
    }
}
