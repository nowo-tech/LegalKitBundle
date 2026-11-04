<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Versioning;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\LegalKitBundle\Versioning\Entity\LegalPageVersion;

use function is_numeric;

/**
 * Published page versions (versioning).
 *
 * @extends ServiceEntityRepository<LegalPageVersion>
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
class LegalPageVersionRepository extends ServiceEntityRepository
{
    /**
     * @param ManagerRegistry $registry Doctrine registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LegalPageVersion::class);
    }

    /**
     * Slug => [highest version, highest version requiring acceptance (0 = none)] of every page with a version row
     * (one grouped query).
     *
     * @return array<string, array{0: int, 1: int}>
     */
    public function requirementsMap(): array
    {
        /** @var list<array{slug: string, current: mixed, required: mixed}> $rows */
        $rows = $this->createQueryBuilder('v')
            ->select('v.slug AS slug', 'MAX(v.version) AS current', 'MAX(CASE WHEN v.requiresAcceptance = true THEN v.version ELSE 0 END) AS required')
            ->groupBy('v.slug')
            ->orderBy('v.slug', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['slug']] = [
                is_numeric($row['current']) ? (int) $row['current'] : 0,
                is_numeric($row['required']) ? (int) $row['required'] : 0,
            ];
        }

        return $map;
    }

    /**
     * The highest version row of a page (null = only the implicit version 1).
     *
     * @return ?LegalPageVersion The version
     */
    public function latest(string $slug): ?LegalPageVersion
    {
        return $this->findOneBy(['slug' => $slug], ['version' => 'DESC']);
    }

    /**
     * Whether upgrade-seed already published a version of the page for this seed revision.
     *
     * @return bool The answer
     */
    public function hasSeedRevision(string $slug, string $revision): bool
    {
        return $this->findOneBy(['slug' => $slug, 'seedRevision' => $revision]) instanceof LegalPageVersion;
    }
}
