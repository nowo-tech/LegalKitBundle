<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Acceptance;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\LegalKitBundle\Acceptance\Entity\LegalAcceptance;

use function array_chunk;
use function array_filter;
use function array_values;
use function is_numeric;
use function is_string;
use function max;

/**
 * Acceptance records (acceptance).
 *
 * Subject ids compare exactly (1.11, audit LQ "identifier columns"): the SQL `=` only pre-filters (a MySQL / MariaDB
 * `_ci` collation also returns `UserA` for `usera`, and ignores trailing spaces); every row is re-checked with `===`
 * before it is returned or deleted, and deletions go by primary key.
 *
 * @extends ServiceEntityRepository<LegalAcceptance>
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
class LegalAcceptanceRepository extends ServiceEntityRepository
{
    /** Primary keys per DELETE statement. */
    public const int DELETE_BATCH = 500;

    /**
     * @param ManagerRegistry $registry Doctrine registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LegalAcceptance::class);
    }

    /**
     * Slug => highest version the subject accepted (one indexed query). The maximum is taken in PHP over the exact
     * matches (1.11): a SQL GROUP BY would fold look-alike subjects together under a `_ci` collation.
     *
     * @return array<string, int>
     */
    public function acceptedVersions(string $subjectId): array
    {
        /** @var list<array{subject: mixed, slug: mixed, version: mixed}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.subjectId AS subject', 'a.slug AS slug', 'a.version AS version')
            ->where('a.subjectId = :subject')
            ->setParameter('subject', $subjectId)
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            // 1.11: a look-alike subject (other case / accents / trailing space) never satisfies this one's pages.
            if ($row['subject'] === $subjectId && is_string($row['slug']) && is_numeric($row['version'])) {
                $map[$row['slug']] = max($map[$row['slug']] ?? 0, (int) $row['version']);
            }
        }

        return $map;
    }

    /**
     * Whether any subject accepted or acknowledged this version of the page.
     *
     * @return bool The answer
     */
    public function hasAcceptances(string $slug, int $version): bool
    {
        return $this->findOneBy(['slug' => $slug, 'version' => $version]) instanceof LegalAcceptance;
    }

    /**
     * Every record of a subject, oldest first.
     *
     * @return list<LegalAcceptance>
     */
    public function forSubject(string $subjectId): array
    {
        $records = $this->findBy(['subjectId' => $subjectId], ['acceptedAt' => 'ASC', 'slug' => 'ASC', 'version' => 'ASC']);

        return array_values(array_filter($records, static fn (LegalAcceptance $record): bool => $record->getSubjectId() === $subjectId));
    }

    /**
     * Every record of a subject as plain values, oldest first (1.11; personal-data export). Array hydration: nothing
     * enters the identity map of the entity manager (worker mode).
     *
     * @return list<array{id: int, slug: string, version: int, locale: string, kind: string, contentHash: string, acceptedAt: DateTimeImmutable}>
     */
    public function rowsForSubject(string $subjectId): array
    {
        /** @var list<array{id: int|string, subject: string, slug: string, version: int|string, locale: string, kind: string, contentHash: string, acceptedAt: DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.id AS id', 'a.subjectId AS subject', 'a.slug AS slug', 'a.version AS version', 'a.locale AS locale', 'a.kind AS kind', 'a.contentHash AS contentHash', 'a.acceptedAt AS acceptedAt')
            ->where('a.subjectId = :subject')
            ->setParameter('subject', $subjectId)
            ->orderBy('a.acceptedAt', 'ASC')
            ->addOrderBy('a.slug', 'ASC')
            ->addOrderBy('a.version', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $records = [];
        foreach ($rows as $row) {
            if ($row['subject'] !== $subjectId) {
                continue;
            }
            $records[] = [
                'id'          => (int) $row['id'],
                'slug'        => $row['slug'],
                'version'     => (int) $row['version'],
                'locale'      => $row['locale'],
                'kind'        => $row['kind'],
                'contentHash' => $row['contentHash'],
                'acceptedAt'  => $row['acceptedAt'],
            ];
        }

        return $records;
    }

    /**
     * Deletes every record of a subject: the ids of the exact matches, then DELETE by primary key in batches of
     * {@see DELETE_BATCH}. Run it inside a transaction ({@see LegalAcceptanceManager::eraseFor()} opens one).
     *
     * @return int Rows deleted
     */
    public function deleteForSubject(string $subjectId): int
    {
        /** @var list<array{id: int|string, subject: string}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.id AS id', 'a.subjectId AS subject')
            ->where('a.subjectId = :subject')
            ->setParameter('subject', $subjectId)
            ->getQuery()
            ->getArrayResult();
        $ids = [];
        foreach ($rows as $row) {
            if ($row['subject'] === $subjectId) {
                $ids[] = (int) $row['id'];
            }
        }

        $total = 0;
        foreach (array_chunk($ids, self::DELETE_BATCH) as $batch) {
            $deleted = $this->createQueryBuilder('a')
                ->delete()
                ->where('a.id IN (:ids)')
                ->setParameter('ids', $batch)
                ->getQuery()
                ->execute();
            $total += is_numeric($deleted) ? (int) $deleted : 0;
        }

        return $total;
    }
}
