<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\User;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Release gate of spec 155 (data model rule 1) on MySQL 8.4: Beacon's `legal_document` DDL (migration
 * Version20260918160000, verbatim) needs no schema change for the bundle entity.
 */
final class MysqlSchemaAdoptionTest extends KernelTestCase
{
    /** Beacon migrations/Version20260918160000.php, verbatim. */
    private const string BEACON_DDL = 'CREATE TABLE legal_document (
            id INT AUTO_INCREMENT NOT NULL,
            slug VARCHAR(32) NOT NULL,
            locale VARCHAR(8) NOT NULL,
            title VARCHAR(180) NOT NULL,
            body LONGTEXT NOT NULL,
            created_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            created_by_id INT DEFAULT NULL,
            updated_by_id INT DEFAULT NULL,
            UNIQUE INDEX uniq_legal_document_slug_locale (slug, locale),
            INDEX IDX_legal_document_created_by (created_by_id),
            INDEX IDX_legal_document_updated_by (updated_by_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_legal_document_created_by FOREIGN KEY (created_by_id) REFERENCES user (id) ON DELETE SET NULL,
            CONSTRAINT FK_legal_document_updated_by FOREIGN KEY (updated_by_id) REFERENCES user (id) ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB';

    public function testBeaconTableNeedsNoSchemaChange(): void
    {
        self::bootKernel();
        $url = (string) $_ENV['ADOPTION_DATABASE_URL'];
        $params = (new DsnParser(['mysql' => 'pdo_mysql']))->parse($url);
        $server = $params;
        unset($server['dbname']);
        $admin = DriverManager::getConnection($server);
        $admin->executeStatement('DROP DATABASE IF EXISTS legal_adoption');
        $admin->executeStatement('CREATE DATABASE legal_adoption CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $admin->close();

        $kernelManager = self::getContainer()->get(EntityManagerInterface::class);
        $connection    = DriverManager::getConnection($params, $kernelManager->getConfiguration());
        $manager       = new EntityManager($connection, $kernelManager->getConfiguration(), $kernelManager->getEventManager());
        $tool          = new SchemaTool($manager);
        $user          = $manager->getClassMetadata(User::class);
        $legal         = $manager->getClassMetadata(LegalDocument::class);

        $tool->createSchema([$user]);
        $connection->executeStatement(self::BEACON_DDL);

        $statements = array_values(array_filter(
            $tool->getUpdateSchemaSql([$user, $legal]),
            static fn (string $sql): bool => str_contains($sql, 'legal_document'),
        ));
        self::assertSame([], $statements, 'doctrine:schema:update --dump-sql must be empty for legal_document');

        $connection->close();
    }
}
