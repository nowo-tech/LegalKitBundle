<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use Nowo\LegalKitBundle\Acceptance\Entity\LegalAcceptance;
use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceManagerInterface;
use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceRepository;
use Nowo\LegalKitBundle\Tests\Support\TestKernel;
use Nowo\LegalKitBundle\Tests\Support\VersioningTestCase;
use PHPUnit\Framework\Attributes\Group;

use function array_column;
use function array_map;
use function str_repeat;

/**
 * Exact subject id matching (1.11, audit LQ "identifier columns") on the database of LEGAL_KIT_TEST_DATABASE_URL
 * (CI job "Subject ids on database servers": MySQL 8.0, MariaDB 11.4, PostgreSQL 17); SQLite in memory without it.
 * A MySQL / MariaDB `_ci` collation returns `u-UserA` for `u-usera`, `u-josé` for `u-jose` and (PAD SPACE) `u-ann ` for
 * `u-ann`: the look-alike must never be erased, exported or count as accepted.
 */
#[Group('database')]
final class PersonalDataDatabaseTest extends VersioningTestCase
{
    public function testLookAlikeSubjectsAreNeverErasedOrExported(): void
    {
        $kernel = $this->acceptanceKernel([], ['app_routes', 'server_database']);
        if (TestKernel::serverDatabaseUrl() !== null) {
            self::assertNotInstanceOf(SQLitePlatform::class, $this->entityManager($kernel)->getConnection()->getDatabasePlatform(), 'runs on the server');
        }
        $manager = $this->service($kernel, LegalAcceptanceManagerInterface::class);
        $repo    = $this->service($kernel, LegalAcceptanceRepository::class);
        // Different versions: a `_ci` unique index would refuse the look-alike of the same version.
        $this->insert($kernel, 'u-usera', 1);
        $this->insert($kernel, 'u-UserA', 2);
        $this->insert($kernel, 'u-jose', 3);
        $this->insert($kernel, 'u-josé', 4);
        $this->insert($kernel, 'u-ann', 5);
        $this->insert($kernel, 'u-ann ', 6);

        foreach (['u-usera' => 1, 'u-UserA' => 2, 'u-jose' => 3, 'u-josé' => 4, 'u-ann' => 5, 'u-ann ' => 6] as $subject => $version) {
            self::assertSame([$version], array_column($manager->exportFor($subject), 'version'), $subject);
            self::assertSame(['terms' => $version], $repo->acceptedVersions($subject), $subject);
            self::assertSame([$version], array_map(static fn (LegalAcceptance $record): int => $record->getVersion(), $repo->forSubject($subject)), $subject);
        }
        $this->entityManager($kernel)->clear();

        self::assertSame(1, $manager->eraseFor('u-usera'));
        self::assertSame(1, $manager->eraseFor('u-jose'));
        self::assertSame(1, $manager->eraseFor('u-ann'));
        self::assertSame(0, $manager->eraseFor('u-usera'), 'idempotent');
        foreach (['u-UserA' => 2, 'u-josé' => 4, 'u-ann ' => 6] as $subject => $version) {
            self::assertSame([$version], array_column($manager->exportFor($subject), 'version'), $subject . ' survives');
        }
    }

    public function testLargeErasureDeletesByPrimaryKeyInBatches(): void
    {
        $kernel     = $this->acceptanceKernel([], ['app_routes', 'server_database']);
        $manager    = $this->service($kernel, LegalAcceptanceManagerInterface::class);
        $count      = LegalAcceptanceRepository::DELETE_BATCH * 2 + 1;
        $connection = $this->entityManager($kernel)->getConnection();
        $connection->beginTransaction();
        for ($version = 1; $version <= $count; ++$version) {
            $this->insert($kernel, 'u-many', $version);
        }
        $this->insert($kernel, 'u-Many', $count + 1);
        $connection->commit();

        self::assertCount($count, $manager->exportFor('u-many'));
        self::assertSame($count, $manager->eraseFor('u-many'));
        self::assertSame(0, $manager->eraseFor('u-many'));
        self::assertCount(1, $manager->exportFor('u-Many'));
        self::assertSame(str_repeat('0', 64), $manager->exportFor('u-Many')[0]['content_hash']);
    }

    private function insert(TestKernel $kernel, string $subject, int $version): void
    {
        $manager = $this->entityManager($kernel);
        $manager->getConnection()->insert($manager->getClassMetadata(LegalAcceptance::class)->getTableName(), [
            'subject_id'   => $subject,
            'slug'         => 'terms',
            'version'      => $version,
            'locale'       => 'en',
            'kind'         => 'accept',
            'content_hash' => str_repeat('0', 64),
            'accepted_at'  => new DateTimeImmutable('2026-01-01 10:00:00'),
        ], ['accepted_at' => Types::DATETIME_IMMUTABLE, 'version' => Types::INTEGER]);
    }
}
