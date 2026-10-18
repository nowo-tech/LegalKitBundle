<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Repository;

use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use Stringable;

use function is_string;
use function method_exists;

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
    /**
     * @param ManagerRegistry $registry Doctrine registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LegalDocument::class);
    }

    /**
     * The stored document of a page in a locale.
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
     * Slug → locale → {updated_at, updated_by} of every stored row (admin index; one query, editors fetch-joined).
     *
     * @return array<string, array<string, array{updated_at: DateTimeInterface|null, updated_by: string|null}>>
     */
    public function customMap(): array
    {
        /** @var list<LegalDocument> $documents */
        $documents = $this->createQueryBuilder('document')
            ->leftJoin('document.createdBy', 'creator')->addSelect('creator')
            ->leftJoin('document.updatedBy', 'editor')->addSelect('editor')
            ->orderBy('document.slug', 'ASC')
            ->addOrderBy('document.locale', 'ASC')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($documents as $document) {
            $editor = $document->getUpdatedBy() ?? $document->getCreatedBy();
            $map[$document->getSlug()][$document->getLocale()] = [
                'updated_at' => $document->getUpdatedAt() ?? $document->getCreatedAt(),
                'updated_by' => $editor !== null ? self::describe($editor) : null,
            ];
        }

        return $map;
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
     * Persists a document; flushes unless `$flush` is false (ops-seed keeps one transaction).
     */
    public function save(LegalDocument $document, bool $flush = true): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($document);
        if ($flush) {
            $manager->flush();
        }
    }

    /**
     * Removes a document and flushes.
     */
    public function remove(LegalDocument $document): void
    {
        $manager = $this->getEntityManager();
        $manager->remove($document);
        $manager->flush();
    }

    /**
     * Map key of a page in a locale.
     */
    public static function key(string $slug, string $locale): string
    {
        return $slug . "\0" . $locale;
    }

    /**
     * Display name of an editor: getUserIdentifier(), then __toString(), then null.
     */
    private static function describe(object $user): ?string
    {
        if (method_exists($user, 'getUserIdentifier')) {
            $identifier = $user->getUserIdentifier();

            return is_string($identifier) ? $identifier : null;
        }

        return $user instanceof Stringable ? (string) $user : null;
    }
}
