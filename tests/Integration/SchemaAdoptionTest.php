<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use Nowo\LegalKitBundle\Tests\Support\Entity\User;
use Nowo\LegalKitBundle\Tests\Support\KernelTestCase;
use Nowo\LegalKitBundle\Tests\Support\TestKernel;

/**
 * Release gate (spec 155, data model rule 1): the `legal_document` table of the adopting apps (Beacon
 * Version20260918160000, here in its SQLite form; the MySQL 8 form runs in the demo) is the bundle mapping as is —
 * `doctrine:schema:update --dump-sql` is empty for the bundle entity.
 */
final class SchemaAdoptionTest extends KernelTestCase
{
    /** Beacon's DDL translated to SQLite (same columns, lengths, nullability, index and FK definitions). */
    public const array BEACON_SQLITE_DDL = [
        'CREATE TABLE legal_document (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, slug VARCHAR(32) NOT NULL, locale VARCHAR(8) NOT NULL, title VARCHAR(180) NOT NULL, body CLOB NOT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, created_by_id INTEGER DEFAULT NULL, updated_by_id INTEGER DEFAULT NULL, CONSTRAINT FK_legal_document_created_by FOREIGN KEY (created_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_legal_document_updated_by FOREIGN KEY (updated_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)',
        'CREATE UNIQUE INDEX uniq_legal_document_slug_locale ON legal_document (slug, locale)',
        'CREATE INDEX IDX_legal_document_created_by ON legal_document (created_by_id)',
        'CREATE INDEX IDX_legal_document_updated_by ON legal_document (updated_by_id)',
    ];

    public function testBeaconTableNeedsNoSchemaChange(): void
    {
        $kernel  = new TestKernel();
        $kernel->boot();
        $manager = $this->manager($kernel);
        $tool    = new SchemaTool($manager);
        $user    = $manager->getClassMetadata(User::class);
        $legal   = $manager->getClassMetadata(LegalDocument::class);
        $tool->createSchema([$user]);
        $connection = $manager->getConnection();
        self::assertInstanceOf(Connection::class, $connection);
        foreach (self::BEACON_SQLITE_DDL as $sql) {
            $connection->executeStatement($sql);
        }

        self::assertSame([], $tool->getUpdateSchemaSql([$user, $legal]));
        $kernel->shutdown();
    }

    public function testMappingMatchesTheContract(): void
    {
        $kernel = new TestKernel();
        $kernel->boot();
        $legal = $this->manager($kernel)->getClassMetadata(LegalDocument::class);

        self::assertSame('legal_document', $legal->getTableName());
        self::assertSame(32, $legal->getFieldMapping('slug')->length);
        self::assertSame(8, $legal->getFieldMapping('locale')->length);
        self::assertSame(180, $legal->getFieldMapping('title')->length);
        self::assertSame('text', $legal->getFieldMapping('body')->type);
        self::assertSame('datetime_immutable', $legal->getFieldMapping('createdAt')->type);
        self::assertTrue($legal->getFieldMapping('updatedAt')->nullable);
        self::assertSame(User::class, $legal->getAssociationTargetClass('createdBy'));
        self::assertSame('SET NULL', $legal->getAssociationMapping('updatedBy')->joinColumns[0]->onDelete);
        self::assertArrayHasKey('uniq_legal_document_slug_locale', $legal->table['uniqueConstraints'] ?? []);
        self::assertArrayHasKey('idx_legal_document_created_by', $legal->table['indexes'] ?? []);
        $kernel->shutdown();
    }

    public function testTablePrefixRenamesTheTableAndItsIndexes(): void
    {
        $kernel = new TestKernel(['doctrine' => ['table_prefix' => 'app_']]);
        $kernel->boot();
        $manager = $this->manager($kernel);
        $legal   = $manager->getClassMetadata(LegalDocument::class);

        self::assertSame('app_legal_document', $legal->getTableName());
        self::assertArrayHasKey('app_uniq_legal_document_slug_locale', $legal->table['uniqueConstraints'] ?? []);
        self::assertArrayHasKey('app_idx_legal_document_updated_by', $legal->table['indexes'] ?? []);
        self::assertSame('user', $manager->getClassMetadata(User::class)->getTableName(), 'Host tables are not prefixed');
        $kernel->shutdown();
    }

    private function manager(TestKernel $kernel): \Doctrine\ORM\EntityManagerInterface
    {
        $manager = $kernel->getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(\Doctrine\ORM\EntityManagerInterface::class, $manager);

        return $manager;
    }
}
