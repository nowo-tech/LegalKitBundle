<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Acceptance;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\LegalKitBundle\Acceptance\Entity\LegalAcceptance;

use function is_numeric;
use function is_string;

/**
 * Acceptance records (acceptance).
 *
 * @extends ServiceEntityRepository<LegalAcceptance>
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
class LegalAcceptanceRepository extends ServiceEntityRepository
{
    /**
     * @param ManagerRegistry $registry Doctrine registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LegalAcceptance::class);
    }

    /**
     * Slug => highest version the subject accepted (one indexed query).
     *
     * @return array<string, int>
     */
    public function acceptedVersions(string $subjectId): array
    {
        /** @var list<array{slug: mixed, version: mixed}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.slug AS slug', 'MAX(a.version) AS version')
            ->where('a.subjectId = :subject')
            ->setParameter('subject', $subjectId)
            ->groupBy('a.slug')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            if (is_string($row['slug']) && is_numeric($row['version'])) {
                $map[$row['slug']] = (int) $row['version'];
            }
        }

        return $map;
    }

    /**
     * Every record of a subject, oldest first.
     *
     * @return list<LegalAcceptance>
     */
    public function forSubject(string $subjectId): array
    {
        return $this->findBy(['subjectId' => $subjectId], ['acceptedAt' => 'ASC', 'slug' => 'ASC', 'version' => 'ASC']);
    }

    /**
     * Deletes every record of a subject.
     *
     * @return int Rows deleted
     */
    public function deleteForSubject(string $subjectId): int
    {
        $deleted = $this->createQueryBuilder('a')
            ->delete()
            ->where('a.subjectId = :subject')
            ->setParameter('subject', $subjectId)
            ->getQuery()
            ->execute();

        return is_numeric($deleted) ? (int) $deleted : 0;
    }
}
