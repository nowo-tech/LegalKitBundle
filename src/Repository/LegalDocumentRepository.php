<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Repository;

use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\LegalKitBundle\Doctrine\LegalKitEntityManager;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Stored legal documents (one row per slug and locale).
 *
 * @extends ServiceEntityRepository<LegalDocument>
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
class LegalDocumentRepository extends ServiceEntityRepository
{
    /** Base token of a page text that is not stored yet (the editor shows the seed). */
    public const string NEW_TOKEN = 'new';

    /**
     * @param ManagerRegistry $registry Doctrine registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LegalDocument::class);
    }

    /**
     * The stored document of a page in a locale.
     *
     * @return ?LegalDocument The result
     */
    public function findOneBySlugAndLocale(string $slug, string $locale): ?LegalDocument
    {
        return $this->findOneBy(['slug' => $slug, 'locale' => $locale]);
    }

    /**
     * Every stored document of the given locales (one query), keyed "slug\0locale".
     *
     * @param list<string> $locales
     *
     * @return array<string, LegalDocument>
     */
    public function findByLocales(array $locales): array
    {
        if ($locales === []) {
            return [];
        }

        $map = [];
        foreach ($this->findBy(['locale' => $locales], ['slug' => 'ASC', 'locale' => 'ASC']) as $document) {
            $map[self::key($document->getSlug(), $document->getLocale())] = $document;
        }

        return $map;
    }

    /**
     * Slug → locale → {updated_at, updated_by, seed_revision} of every stored row (admin index; one query, editors fetch-joined).
     *
     * @return array<string, array<string, array{updated_at: DateTimeInterface|null, updated_by: string|null, seed_revision: string|null}>>
     */
    public function customMap(): array
    {
        $relation = $this->getClassMetadata()->hasAssociation('updatedBy');
        $builder  = $this->createQueryBuilder('document');
        if ($relation) {
            $builder->leftJoin('document.createdBy', 'creator')->addSelect('creator')
                ->leftJoin('document.updatedBy', 'editor')->addSelect('editor');
        }
        /** @var list<LegalDocument> $documents */
        $documents = $builder
            ->orderBy('document.slug', 'ASC')
            ->addOrderBy('document.locale', 'ASC')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($documents as $document) {
            if ($relation) {
                $editor = $document->getUpdatedBy() ?? $document->getCreatedBy();
                $editor = $editor !== null ? $this->describe($editor) : null;
            } else {
                // doctrine.blame actor_id (string ids) or none (both null).
                $editor = $document->getUpdatedByActorId() ?? $document->getCreatedByActorId();
            }
            $map[$document->getSlug()][$document->getLocale()] = [
                'updated_at'    => $document->getUpdatedAt() ?? $document->getCreatedAt(),
                'updated_by'    => $editor,
                'seed_revision' => $document->getSeedRevision(),
            ];
        }

        return $map;
    }

    /**
     * Rows whose known seed base is another revision ("built-in text changed"; versioning on only), sorted.
     *
     * @return list<LegalDocument>
     */
    public function findOutdated(string $revision): array
    {
        /* @var list<LegalDocument> */
        return $this->createQueryBuilder('document')
            ->where('document.seedRevision IS NOT NULL')
            ->andWhere('document.seedRevision <> :revision')
            ->setParameter('revision', $revision)
            ->orderBy('document.slug', 'ASC')
            ->addOrderBy('document.locale', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Number of outdated rows (one COUNT; versioning on only).
     *
     * @return int The count
     */
    public function countOutdated(string $revision): int
    {
        return (int) $this->createQueryBuilder('document')
            ->select('COUNT(document.id)')
            ->where('document.seedRevision IS NOT NULL')
            ->andWhere('document.seedRevision <> :revision')
            ->setParameter('revision', $revision)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Number of rows without a seed base (versioning on only; doctor).
     *
     * @return int The count
     */
    public function countWithoutSeedBase(): int
    {
        return (int) $this->createQueryBuilder('document')
            ->select('COUNT(document.id)')
            ->where('document.seedRevision IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Every stored document, sorted by slug and locale (export).
     *
     * @return list<LegalDocument>
     */
    public function findAllSorted(): array
    {
        return $this->findBy([], ['slug' => 'ASC', 'locale' => 'ASC']);
    }

    /**
     * Persists a document; flushes unless `$flush` is false (ops-seed keeps one transaction). Through the bundle's
     * private manager (1.12, `doctrine.entity_manager` unset) it always flushes: that manager holds only bundle rows
     * and nobody else flushes it (inside an open transaction the row joins it).
     *
     * @return void Nothing (side effects only)
     */
    public function save(LegalDocument $document, bool $flush = true): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($document);
        if ($flush || $manager instanceof LegalKitEntityManager) {
            $manager->flush();
        }
    }

    /**
     * Base token of a stored text: a hash of the title and the body, which are what a stale edit page would overwrite.
     * A page text without a stored row has {@see self::NEW_TOKEN}. The editor puts it in the form and checks it
     * again, under a row lock, when the form is saved (REQ-RUNTIME-003 rule 4), with or without versioning.
     *
     * @return string The token
     */
    public static function token(string $title, string $body): string
    {
        return hash('sha256', $title . "\0" . $body);
    }

    /**
     * Token of the stored text read from the database (never the unit of work) under a pessimistic write lock, inside
     * the caller's transaction. A concurrent save of the same row waits here until the caller ends its transaction.
     *
     * @return string The token, {@see self::NEW_TOKEN} when no row exists
     */
    public function lockedToken(string $slug, string $locale): string
    {
        /** @var list<array{title: string, body: string}> $rows */
        $rows = $this->getEntityManager()->createQuery('SELECT d.title AS title, d.body AS body FROM ' . LegalDocument::class . ' d WHERE d.slug = :slug AND d.locale = :locale')
            ->setParameter('slug', $slug)
            ->setParameter('locale', $locale)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getArrayResult();

        return isset($rows[0]) ? self::token($rows[0]['title'], $rows[0]['body']) : self::NEW_TOKEN;
    }

    /**
     * Saves a document only when the stored text is still the one the form was built from: one transaction, the row
     * locked, the base token compared, then the flush. A stale edit changes nothing and the managed row gets its stored
     * values back inside the unit (its closing flush would otherwise store the edit), so no later flush of the same
     * manager can store it either.
     *
     * @param string $baseToken The token the form carried
     *
     * @return bool True when saved, false when the stored text changed meanwhile
     */
    public function saveIfCurrent(LegalDocument $document, string $baseToken): bool
    {
        $manager = $this->getEntityManager();
        /** @var bool $saved */
        // @igor-ignore - The closure runs at once inside the unit and is dropped; the repository keeps no reference to it.
        $saved = $manager->wrapInTransaction(function (EntityManagerInterface $manager) use ($document, $baseToken): bool {
            if ($this->lockedToken($document->getSlug(), $document->getLocale()) !== $baseToken) {
                // The unit ends with a flush: give the managed row its stored values back first, or the refused edit
                // would be stored by that very flush.
                $this->discardChanges($document);

                return false;
            }
            $manager->persist($document);
            $manager->flush();

            return true;
        });

        return $saved;
    }

    /**
     * Gives a managed row its stored values back (a refused stale edit). Nothing for a new or detached row, or when the
     * manager is closed. Never clears the manager.
     *
     * @return void Nothing (side effects only)
     */
    public function discardChanges(LegalDocument $document): void
    {
        $manager = $this->getEntityManager();
        if ($manager->isOpen() && $document->getId() !== null && $manager->contains($document)) {
            $manager->refresh($document);
        }
    }

    /**
     * Removes a document and flushes.
     *
     * @return void Nothing (side effects only)
     */
    public function remove(LegalDocument $document): void
    {
        $manager = $this->getEntityManager();
        // @igor-ignore - Doctrine unit of work is request-scoped (entity manager reset between requests)
        $manager->remove($document);
        $manager->flush();
    }

    /**
     * Map key of a page in a locale.
     *
     * @return string The value
     */
    public static function key(string $slug, string $locale): string
    {
        return $slug . "\0" . $locale;
    }

    /**
     * Display name of an editor (its user identifier), null for a user object without one.
     */
    private function describe(object $user): ?string
    {
        return $user instanceof UserInterface ? $user->getUserIdentifier() : null;
    }
}
