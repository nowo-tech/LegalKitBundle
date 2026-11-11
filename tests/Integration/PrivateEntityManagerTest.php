<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Integration;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\LegalKitBundle\Acceptance\Entity\LegalAcceptance;
use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceRepository;
use Nowo\LegalKitBundle\Blame\LegalActorId;
use Nowo\LegalKitBundle\Command\SeedCommand;
use Nowo\LegalKitBundle\Controller\Admin\LegalDocumentAdminController;
use Nowo\LegalKitBundle\DependencyInjection\NowoLegalKitExtension;
use Nowo\LegalKitBundle\Doctrine\LegalKitEntityManager;
use Nowo\LegalKitBundle\Doctrine\LegalKitEntityManagerSubscriber;
use Nowo\LegalKitBundle\Doctrine\LegalKitManagerRegistry;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use Nowo\LegalKitBundle\Portability\LegalDocumentImportInterface;
use Nowo\LegalKitBundle\Portability\LegalDocumentPortability;
use Nowo\LegalKitBundle\Portability\LegalDocumentPortabilityInterface;
use Nowo\LegalKitBundle\Repository\LegalDocumentRepository;
use Nowo\LegalKitBundle\Tests\Support\Entity\User;
use Nowo\LegalKitBundle\Tests\Support\TestKernel;
use Nowo\LegalKitBundle\Tests\Support\VersioningTestCase;
use Nowo\LegalKitBundle\Versioning\Entity\LegalPageVersion;
use Nowo\LegalKitBundle\Versioning\LegalPageVersionRepository;
use Nowo\LegalKitBundle\Versioning\LegalPageVersions;
use Nowo\LegalKitBundle\Versioning\LegalPageVersionsInterface;
use Nowo\LegalKitBundle\Versioning\LegalSeedUpgrader;
use stdClass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Contracts\Service\ResetInterface;

/**
 * 1.12, architecture bar rule 6 on the zero-config path: without `doctrine.entity_manager` the bundle writes through
 * its private entity manager ({@see LegalKitEntityManager}). Proves that the host's pending changes are never flushed
 * by a bundle write, that no write is lost (host transactions and their rollback, a closed manager, the worker
 * boundaries, a user entity of the host manager as an actor), the `private_entity_manager: false` escape hatch and
 * `doctrine.connection` (rule 8).
 */
final class PrivateEntityManagerTest extends VersioningTestCase
{
    public function testZeroConfigServicesAndRepositoriesUseThePrivateManager(): void
    {
        $kernel  = $this->versioningKernel(['acceptance' => ['enabled' => true, 'subject_resolver' => 'test.legal_kit.subject_resolver']]);
        $private = $this->private($kernel);
        $host    = $this->entityManager($kernel);

        self::assertTrue($kernel->getContainer()->getParameter(NowoLegalKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER));
        self::assertNotSame($host, $private);
        foreach ([LegalPageVersions::class, LegalDocumentPortability::class, LegalDocumentAdminController::class, SeedCommand::class, LegalSeedUpgrader::class] as $service) {
            self::assertSame($private, (fn (): ?object => $this->entityManager)->call($this->service($kernel, $service)), $service);
        }
        foreach ([LegalDocumentRepository::class, LegalPageVersionRepository::class, LegalAcceptanceRepository::class] as $repository) {
            self::assertSame($private, (fn (): object => $this->getEntityManager())->call($this->service($kernel, $repository)), $repository);
        }
        // The application's manager hands out the same (bundle-bound) repository service; its own unit of work is untouched.
        self::assertSame($this->service($kernel, LegalDocumentRepository::class), $host->getRepository(LegalDocument::class));
        self::assertSame($private->live(), $private->live(), 'one private manager per unit of work');
        self::assertNotSame($host->getUnitOfWork(), $private->getUnitOfWork());
        self::assertSame($host->getConnection(), $private->getConnection(), 'same connection: a host transaction includes the bundle rows');
        self::assertSame($host, $private->source());
        self::assertTrue($this->testContainer($kernel)->has(LegalKitEntityManagerSubscriber::class));
    }

    public function testBundleWritesNeverFlushTheHostUnitOfWork(): void
    {
        $kernel = $this->versioningKernel(['seed' => ['materialize' => true]]);
        $host   = $this->entityManager($kernel);
        $actor  = new User('ops@example.com');
        $host->persist($actor);
        $host->flush();
        $pending = new User('pending@example.com');
        $host->persist($pending);

        $versions = $this->service($kernel, LegalPageVersionsInterface::class);
        self::assertSame(2, $versions->publishVersion('terms', true, LegalPageVersion::SOURCE_EDITOR)->version);
        self::assertSame(3, $versions->publishVersion('terms', false, LegalPageVersion::SOURCE_EDITOR, [], null, null, false)->version);
        self::assertSame(4, $this->service($kernel, LegalPageVersions::class)->publish('terms', false, LegalPageVersion::SOURCE_EDITOR)->getVersion());
        $import = $this->service($kernel, LegalDocumentImportInterface::class);
        self::assertSame(1, $import->import([['slug' => 'notice', 'locale' => 'en', 'title' => 'Notice', 'body' => '<p>A</p>']], new LegalActorId('acc-1'), true));
        self::assertSame(1, $import->import([['slug' => 'terms', 'locale' => 'en', 'title' => 'Terms', 'body' => '<p>T</p>']]));
        // The deprecated user-object actor of the host manager, on an existing row (relation blame): adopted as a
        // reference of the private manager, so the update is written instead of failing on a "new" related entity.
        self::assertSame(1, $this->service($kernel, LegalDocumentPortabilityInterface::class)->apply([['slug' => 'notice', 'locale' => 'en', 'title' => 'Notice 2', 'body' => '<p>B</p>']], $actor, true));
        $repository = $this->service($kernel, LegalDocumentRepository::class);
        $terms      = $repository->findOneBySlugAndLocale('terms', 'en');
        self::assertInstanceOf(LegalDocument::class, $terms);
        $terms->setTitle('Terms 2');
        $repository->save($terms, false);
        self::assertSame(Command::SUCCESS, $this->tester($kernel, SeedCommand::NAME)->execute([]));
        $repository->remove($terms);

        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($pending), 'the host change is still pending');
        $connection = $host->getConnection();
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM `user`'), 'and was never written by the bundle');
        self::assertSame(4, (int) $connection->fetchOne("SELECT MAX(version) FROM legal_page_version WHERE slug = 'terms'"));
        self::assertSame('Notice 2', $connection->fetchOne("SELECT title FROM legal_document WHERE slug = 'notice' AND locale = 'en'"));
        self::assertFalse($connection->fetchOne("SELECT title FROM legal_document WHERE slug = 'terms' AND locale = 'en'"), 'removed');
        self::assertGreaterThan(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM legal_document'), 'the seed rows');
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM legal_document WHERE slug = 'notice' AND locale = 'en'"), 'the stored row is not seeded again');

        $host->flush();
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM `user`'), 'the host flushes its own change itself');
    }

    public function testWritesJoinTheHostTransactionAndARollbackLeavesNoStaleRow(): void
    {
        $kernel     = $this->versioningKernel();
        $import     = $this->service($kernel, LegalDocumentImportInterface::class);
        $connection = $this->entityManager($kernel)->getConnection();
        $count      = static fn (): int => (int) $connection->fetchOne("SELECT COUNT(*) FROM legal_document WHERE slug = 'notice'");
        $row        = [['slug' => 'notice', 'locale' => 'en', 'title' => 'Notice', 'body' => '<p>A</p>']];

        // flush: false inside a host transaction: the row joins it and goes away with its rollback.
        $connection->beginTransaction();
        self::assertSame(1, $import->import($row));
        self::assertSame(1, $count(), 'written inside the host transaction');
        $connection->rollBack();
        self::assertSame(0, $count());

        // The private manager forgot the rolled-back row: the same import inserts it again (no stale identity).
        $connection->beginTransaction();
        self::assertSame(1, $import->import($row));
        $connection->commit();
        self::assertSame(1, $count());
        $stored = $this->service($kernel, LegalDocumentRepository::class)->findOneBySlugAndLocale('notice', 'en');
        self::assertSame('Notice', $stored?->getTitle());

        // A rolled-back nested transaction (savepoint) of the host: the update is undone and not served from memory.
        $connection->beginTransaction();
        $connection->beginTransaction();
        $import->import([['slug' => 'notice', 'locale' => 'en', 'title' => 'Rolled back', 'body' => '<p>R</p>']]);
        $connection->rollBack();
        $connection->commit();
        $again = $this->service($kernel, LegalDocumentRepository::class)->findOneBySlugAndLocale('notice', 'en');
        self::assertSame('Notice', $again?->getTitle());
        self::assertSame('Notice', $connection->fetchOne("SELECT title FROM legal_document WHERE slug = 'notice'"));
    }

    public function testAClosedPrivateManagerIsReplacedAndTheHostManagerStaysOpen(): void
    {
        $kernel   = $this->versioningKernel();
        $private  = $this->private($kernel);
        $host     = $this->entityManager($kernel);
        $versions = $this->service($kernel, LegalPageVersionsInterface::class);
        $pending  = new User('pending@example.com');
        $host->persist($pending);

        $taken  = $versions->publishVersion('terms', true, LegalPageVersion::SOURCE_EDITOR)->version;
        $before = $private->live();
        try {
            $versions->publishVersion('terms', true, LegalPageVersion::SOURCE_EDITOR, [], null, $taken);
            self::fail('Expected the duplicate version.');
        } catch (UniqueConstraintViolationException) {
            self::assertFalse($before->isOpen(), 'the failed flush closed the private manager');
        }
        self::assertTrue($host->isOpen(), 'never the host manager');
        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($pending));

        // The next call (same unit of work) gets a fresh manager: the write is not lost.
        self::assertSame($taken + 1, $versions->publishVersion('terms', true, LegalPageVersion::SOURCE_EDITOR)->version);
        self::assertNotSame($before, $private->live());
        self::assertTrue($private->isOpen());

        // At a boundary, a closed manager is dropped before the unit of work starts.
        $private->close();
        $private->recover();
        self::assertFalse($private->isCreated());
        self::assertSame($taken + 2, $versions->publishVersion('terms', true, LegalPageVersion::SOURCE_EDITOR)->version);
        $host->flush();
        self::assertSame(1, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM `user`'));
    }

    public function testWorkerBoundariesClearThePrivateManagerAndKernelResetDropsIt(): void
    {
        $kernel  = $this->versioningKernel();
        $private = $this->private($kernel);
        $host    = $this->entityManager($kernel);
        $import  = $this->service($kernel, LegalDocumentImportInterface::class);
        $import->import([['slug' => 'notice', 'locale' => 'en', 'title' => 'Notice', 'body' => '<p>A</p>']], null, true);
        $document = $this->service($kernel, LegalDocumentRepository::class)->findOneBySlugAndLocale('notice', 'en');
        self::assertInstanceOf(LegalDocument::class, $document);
        self::assertTrue($private->contains($document));
        $user = new User('managed@example.com');
        $host->persist($user);
        $host->flush();

        // http_kernel directly (Kernel::handle() would run the services resetter): request start and terminate.
        $httpKernel = $this->testContainer($kernel)->get('http_kernel');
        self::assertInstanceOf(HttpKernel::class, $httpKernel);
        $request  = Request::create('/no-such-page');
        $response = $httpKernel->handle($request);
        self::assertFalse($private->contains($document), 'cleared at the request boundary');
        $httpKernel->terminate($request, $response);
        self::assertTrue($host->contains($user), 'the host manager is never cleared by the bundle');

        // A stale row changed by another worker is read again in the next unit of work.
        $host->getConnection()->update(LegalDocument::TABLE, ['title' => 'Edited elsewhere'], ['slug' => 'notice', 'locale' => 'en']);
        $subscriber = $this->service($kernel, LegalKitEntityManagerSubscriber::class);
        $subscriber->begin();
        self::assertSame('Edited elsewhere', $this->service($kernel, LegalDocumentRepository::class)->findOneBySlugAndLocale('notice', 'en')?->getTitle());
        $subscriber->end();
        self::assertTrue($private->isCreated());

        $resetter = $this->testContainer($kernel)->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();
        self::assertFalse($private->isCreated(), 'kernel.reset drops the private manager');
        self::assertSame('Edited elsewhere', $this->service($kernel, LegalDocumentRepository::class)->findOneBySlugAndLocale('notice', 'en')?->getTitle(), 're-created on use');
    }

    public function testTheSharedManagerEscapeHatchKeepsThe111Behaviour(): void
    {
        $kernel = $this->versioningKernel(['doctrine' => ['private_entity_manager' => false]]);
        $host   = $this->entityManager($kernel);

        self::assertFalse($kernel->getContainer()->getParameter(NowoLegalKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER));
        self::assertFalse($this->testContainer($kernel)->has(LegalKitEntityManager::class));
        self::assertFalse($this->testContainer($kernel)->has(LegalKitManagerRegistry::class));
        $versions = $this->service($kernel, LegalPageVersions::class);
        self::assertSame($host, (fn (): object => $this->entityManager)->call($versions));
        self::assertSame($host, (fn (): object => $this->getEntityManager())->call($this->service($kernel, LegalDocumentRepository::class)));
        // Documented 1.11 behaviour: a flush through the shared manager writes the host's pending change too.
        $host->persist(new User('pending@example.com'));
        $versions->publishVersion('terms', true, LegalPageVersion::SOURCE_EDITOR);
        self::assertSame(1, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM `user`'));
    }

    public function testThePrivateManagerOnItsOwnConnection(): void
    {
        $kernel  = $this->connectionKernel();
        $legal   = $this->registry($kernel)->getConnection('legal');
        $host    = $this->entityManager($kernel);
        $private = $this->private($kernel);
        self::assertSame($legal, $private->getConnection());
        (new SchemaTool($private))->createSchema($private->getMetadataFactory()->getAllMetadata());

        $pending = new User('pending@example.com');
        $host->persist($pending);
        $this->service($kernel, LegalDocumentImportInterface::class)->import([['slug' => 'notice', 'locale' => 'en', 'title' => 'Notice', 'body' => '<p>A</p>']], new LegalActorId('acc-1'), true);
        $this->service($kernel, LegalPageVersionsInterface::class)->publishVersion('terms', true, LegalPageVersion::SOURCE_EDITOR);

        self::assertSame(1, (int) $private->getConnection()->fetchOne('SELECT COUNT(*) FROM legal_document'));
        self::assertSame(0, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM legal_document'), 'nothing on the default connection');
        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($pending));
        self::assertSame('acc-1', $private->getConnection()->fetchOne('SELECT updated_by FROM legal_document'));
    }

    public function testConnectionOptionMisuseFailsTheBuild(): void
    {
        foreach ([
            [['doctrine' => ['connection' => 'legal']], 'needs nowo_legal_kit.doctrine.blame: actor_id or none'],
            [['doctrine' => ['connection' => 'legal', 'blame' => 'none', 'private_entity_manager' => false]], 'keep private_entity_manager true'],
            [['doctrine' => ['connection' => 'missing', 'blame' => 'none']], 'no such Doctrine DBAL connection'],
            [['doctrine' => ['connection' => 'not valid']], 'literal DBAL connection name'],
        ] as [$config, $message]) {
            try {
                $this->bootKernel($config);
                self::fail('Expected the build to fail: ' . $message);
            } catch (InvalidConfigurationException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    public function testTheRegistryDelegatesEverythingButTheBundleEntities(): void
    {
        $kernel   = $this->versioningKernel();
        $doctrine = $this->registry($kernel);
        $registry = $this->service($kernel, LegalKitManagerRegistry::class);
        $private  = $this->private($kernel);

        self::assertSame($private, $registry->getManagerForClass(LegalDocument::class));
        self::assertSame($private, $registry->getManagerForClass(LegalPageVersion::class));
        self::assertSame($doctrine->getManagerForClass(User::class), $registry->getManagerForClass(User::class));
        self::assertSame($this->service($kernel, LegalDocumentRepository::class), $registry->getRepository(LegalDocument::class));
        self::assertSame($doctrine->getRepository(User::class), $registry->getRepository(User::class));
        self::assertSame($doctrine->getRepository(LegalDocument::class, 'default'), $registry->getRepository(LegalDocument::class, 'default'), 'a named manager is the application\'s');
        self::assertSame($doctrine->getDefaultConnectionName(), $registry->getDefaultConnectionName());
        self::assertSame($doctrine->getConnection(), $registry->getConnection());
        self::assertSame($doctrine->getConnections(), $registry->getConnections());
        self::assertSame($doctrine->getConnectionNames(), $registry->getConnectionNames());
        self::assertSame($doctrine->getDefaultManagerName(), $registry->getDefaultManagerName());
        self::assertSame($doctrine->getManager(), $registry->getManager());
        self::assertSame($doctrine->getManagers(), $registry->getManagers());
        self::assertSame($doctrine->getManagerNames(), $registry->getManagerNames());
        self::assertSame($doctrine->getManager(), $registry->resetManager());
        self::assertFalse(LegalKitManagerRegistry::isBundleEntity('Nowo\\LegalKitBundle\\Tests\\Support\\Entity\\User'));
        self::assertTrue(LegalKitManagerRegistry::isBundleEntity(LegalAcceptance::class));
    }

    public function testAdoptAndMisconfiguredSources(): void
    {
        $kernel  = $this->versioningKernel();
        $private = $this->private($kernel);
        $host    = $this->entityManager($kernel);
        $user    = new User('ops@example.com');
        $host->persist($user);
        $host->flush();

        $reference = $private->adopt($user);
        self::assertNotSame($user, $reference);
        self::assertTrue($private->contains($reference));
        self::assertSame($reference, $private->adopt($reference), 'already managed by the private manager');
        $plain = new stdClass();
        self::assertSame($plain, $private->adopt($plain), 'not mapped');
        $new = new User('new@example.com');
        self::assertSame($new, $private->adopt($new), 'no identifier yet');

        // Only the decorator's own property is magic.
        try {
            $private->__get('other');
            self::fail('Expected an undefined property.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('$other', $exception->getMessage());
        }

        $empty = $this->createStub(ManagerRegistry::class);
        $empty->method('getManagerForClass')->willReturn(null);
        try {
            (new LegalKitEntityManager($empty))->live();
            self::fail('Expected an unmapped entity.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('No Doctrine ORM entity manager maps', $exception->getMessage());
        }

        $odd = $this->createStub(ManagerRegistry::class);
        $odd->method('getManagerForClass')->willReturn($host);
        $odd->method('getConnection')->willReturn(new stdClass());
        try {
            (new LegalKitEntityManager($odd, 'odd'))->live();
            self::fail('Expected a non-DBAL connection.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('"odd" is not a Doctrine DBAL connection', $exception->getMessage());
        }

        // Transaction notifications: only a rollback marks the manager stale; a fresh one is created on the next call.
        $before = $private->live();
        $private->transactionStarted($host->getConnection());
        $private->savepointCreated($host->getConnection(), 'S1');
        $private->savepointReleased($host->getConnection(), 'S1');
        $private->transactionEnded($host->getConnection(), true);
        self::assertSame($before, $private->live());
        $private->savepointRolledBack($host->getConnection(), 'S2');
        $private->recover();
        self::assertFalse($private->isCreated(), 'a stale manager is dropped at the boundary');
        self::assertNotSame($before, $private->live());
    }

    private function connectionKernel(): TestKernel
    {
        return $this->versioningKernel(['doctrine' => ['connection' => 'legal', 'blame' => 'actor_id']], [], [
            'doctrine' => ['dbal' => ['connections' => ['legal' => ['url' => 'sqlite:///:memory:']]]],
        ]);
    }

    private function private(TestKernel $kernel): LegalKitEntityManager
    {
        return $this->service($kernel, LegalKitEntityManager::class);
    }

    private function registry(TestKernel $kernel): ManagerRegistry
    {
        $registry = $this->testContainer($kernel)->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return $registry;
    }
}
