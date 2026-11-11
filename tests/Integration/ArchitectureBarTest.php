<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Integration;

use ArrayObject;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\LegalKitBundle\Acceptance\Entity\LegalAcceptance;
use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceManagerInterface;
use Nowo\LegalKitBundle\Blame\LegalActorId;
use Nowo\LegalKitBundle\Command\SeedCommand;
use Nowo\LegalKitBundle\Controller\Admin\LegalDocumentAdminController;
use Nowo\LegalKitBundle\Doctrine\LegalPendingEventsInterface;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use Nowo\LegalKitBundle\Event\LegalAcceptancesErasedEvent;
use Nowo\LegalKitBundle\Event\LegalAcceptedEvent;
use Nowo\LegalKitBundle\Event\LegalDocumentsImportedEvent;
use Nowo\LegalKitBundle\Event\LegalVersionPublishedEvent;
use Nowo\LegalKitBundle\Portability\LegalDocumentPortabilityInterface;
use Nowo\LegalKitBundle\Tests\Support\Entity\User;
use Nowo\LegalKitBundle\Tests\Support\TestKernel;
use Nowo\LegalKitBundle\Tests\Support\VersioningTestCase;
use Nowo\LegalKitBundle\Versioning\Entity\LegalPageVersion;
use Nowo\LegalKitBundle\Versioning\LegalPageVersions;
use Nowo\LegalKitBundle\Versioning\LegalPageVersionsInterface;
use Nowo\LegalKitBundle\Versioning\LegalPageVersionView;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernel;

use function dirname;

/**
 * 1.10 architecture bar: the host's pending changes and transactions (rules 6 / 7), the DTO ports (rules 1 / 3), the
 * erase / seed events (rule 4) and the dedicated `doctrine.entity_manager` (standard host and DDD host).
 */
final class ArchitectureBarTest extends VersioningTestCase
{
    public function testRecordAndEraseEventsFollowTheHostTransaction(): void
    {
        $kernel     = $this->acceptanceKernel();
        $manager    = $this->service($kernel, LegalAcceptanceManagerInterface::class);
        $events     = $this->listen($kernel, [LegalAcceptedEvent::class, LegalAcceptancesErasedEvent::class]);
        $connection = $this->entityManager($kernel)->getConnection();

        // record() inside a host transaction: rows join it, the events wait for the outermost commit.
        $connection->beginTransaction();
        self::assertSame(2, $manager->record('u-ann', $manager->pendingFor('u-ann', 'en')));
        self::assertSame([], $events->getArrayCopy(), 'nothing before the host commit');
        $connection->commit();
        self::assertSame([], $events->getArrayCopy(), 'never from inside the commit');
        self::assertSame(2, $this->service($kernel, LegalPendingEventsInterface::class)->dispatchPending(), 'the next safe point');
        self::assertSame([LegalAcceptedEvent::class . ':u-ann', LegalAcceptedEvent::class . ':u-ann'], $events->getArrayCopy());

        // Rolled back: no row, no event.
        $connection->beginTransaction();
        $manager->record('u-bob', $manager->pendingFor('u-bob', 'en'));
        $connection->rollBack();
        self::assertCount(2, $manager->pendingFor('u-bob', 'en'));
        self::assertCount(2, $events);

        // Without a host transaction: one transaction of its own, the events right after it.
        self::assertSame(2, $manager->record('u-bob', $manager->pendingFor('u-bob', 'en')));
        self::assertFalse($connection->isTransactionActive());
        self::assertCount(4, $events);

        // eraseFor(): event after the commit, held by a host transaction, dropped on its rollback.
        $connection->beginTransaction();
        self::assertSame(2, $manager->eraseFor('u-bob'));
        $connection->rollBack();
        self::assertCount(4, $events);
        self::assertSame(2, $manager->eraseFor('u-bob'));
        self::assertSame(LegalAcceptancesErasedEvent::class . ':u-bob', $events[4]);
        self::assertSame(0, $manager->eraseFor('u-nobody'));
        self::assertCount(6, $events);
    }

    /**
     * W1-B: the committed events are dispatched outside the commit; a listener that writes commits its own row, and
     * the end of the request dispatches what the host committed.
     */
    public function testCommittedEventsReachAListenerThatWritesAtTheEndOfTheRequest(): void
    {
        $kernel     = $this->acceptanceKernel();
        $manager    = $this->service($kernel, LegalAcceptanceManagerInterface::class);
        $connection = $this->entityManager($kernel)->getConnection();
        $active     = new ArrayObject();
        $this->service($kernel, EventDispatcherInterface::class)->addListener(LegalAcceptedEvent::class, static function (LegalAcceptedEvent $event) use ($connection, $active): void {
            $active->append($connection->isTransactionActive());
            $connection->transactional(static fn (Connection $c): int|string => $c->insert('`user`', ['email' => $event->subjectId . '-' . $event->slug . '@example.com', 'roles' => '[]']));
        });

        $connection->beginTransaction();
        $manager->record('u-ann', $manager->pendingFor('u-ann', 'en'));
        $connection->commit();
        self::assertSame([], $active->getArrayCopy());

        $httpKernel = $this->testContainer($kernel)->get('http_kernel');
        self::assertInstanceOf(HttpKernel::class, $httpKernel);
        $request = Request::create('/no-such-page');
        $httpKernel->terminate($request, $httpKernel->handle($request));

        self::assertSame([false, false], $active->getArrayCopy(), 'the listeners ran outside any transaction');
        self::assertFalse($connection->isTransactionActive());
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM `user`'), 'their own rows are committed');

        // Savepoint rolled back inside a committed transaction: those events are dropped.
        $connection->beginTransaction();
        $connection->beginTransaction();
        $manager->record('u-bob', $manager->pendingFor('u-bob', 'en'));
        $connection->rollBack();
        $connection->commit();
        self::assertSame(0, $this->service($kernel, LegalPendingEventsInterface::class)->dispatchPending());
        self::assertCount(2, $active);
    }

    public function testVersionsPortWithDtosAndTheHostControlledUnitOfWork(): void
    {
        $kernel   = $this->versioningKernel();
        $versions = $this->service($kernel, LegalPageVersionsInterface::class);
        self::assertInstanceOf(LegalPageVersions::class, $versions);
        $events = $this->listen($kernel, [LegalVersionPublishedEvent::class]);
        $host   = $this->entityManager($kernel);

        self::assertNull($versions->latestVersion('privacy'));
        $view = $versions->publishVersion('terms', true, LegalPageVersion::SOURCE_EDITOR, ['en' => 'New terms']);
        self::assertSame(['terms', 2, true, 'New terms'], [$view->slug, $view->version, $view->requiresAcceptance, $view->summaryFor('en')]);
        self::assertNull($view->summaryFor('de'));
        self::assertSame(2, $versions->latestVersion('terms')?->version);
        self::assertCount(1, $events);

        // flush: false — only persisted: a pending host entity is not flushed by the bundle; the host flushes,
        // commits and calls afterPublish().
        $host->persist(new User('pending@example.com'));
        $pending = $versions->publishVersion('terms', false, LegalPageVersion::SOURCE_EDITOR, [], null, null, false);
        self::assertSame(3, $pending->version);
        self::assertSame(0, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM `user`'));
        self::assertSame(2, $versions->currentUncached('terms'));
        $host->flush();
        $versions->afterPublish($pending);
        self::assertSame(3, $versions->current('terms'));
        self::assertCount(2, $events);

        // Deprecated entity-typed methods keep working.
        $entity = $versions->publish('terms', false, LegalPageVersion::SOURCE_EDITOR);
        self::assertSame(4, $entity->getVersion());
        self::assertSame(4, $versions->latest('terms')?->getVersion());
        $versions->afterCommit($entity);
        self::assertCount(4, $events);
        self::assertSame(4, LegalPageVersionView::fromEntity($entity)->version);
    }

    public function testImportWithAnActorIdAndSeedCommandEvent(): void
    {
        $kernel      = $this->versioningKernel(['doctrine' => ['blame' => 'actor_id'], 'seed' => ['materialize' => true]]);
        $events      = $this->listen($kernel, [LegalDocumentsImportedEvent::class]);
        $portability = $this->service($kernel, LegalDocumentPortabilityInterface::class);

        $portability->apply([['slug' => 'notice', 'locale' => 'en', 'title' => 'Notice', 'body' => '<p>A</p>']], new LegalActorId('acc-42'), true);
        $notice = $this->find($kernel, 'notice', 'en');
        self::assertSame(['acc-42', 'acc-42'], [$notice?->getCreatedByActorId(), $notice?->getUpdatedByActorId()]);
        $portability->apply([['slug' => 'notice', 'locale' => 'en', 'title' => 'Notice 2', 'body' => '<p>B</p>']], new LegalActorId('acc-7'), true);
        $notice = $this->find($kernel, 'notice', 'en');
        self::assertSame(['acc-42', 'acc-7'], [$notice?->getCreatedByActorId(), $notice?->getUpdatedByActorId()]);
        self::assertCount(2, $events);

        self::assertSame(Command::SUCCESS, $this->tester($kernel, SeedCommand::NAME)->execute([]));
        self::assertCount(3, $events);
        $seeded = $events[2];
        self::assertIsArray($seeded);
        self::assertNotContains(['slug' => 'notice', 'locale' => 'en'], $seeded, 'the stored row is not seeded again');
        self::assertContains(['slug' => 'privacy', 'locale' => 'en'], $seeded);
    }

    public function testDedicatedManagerWithAutoMappingOnTheDefaultOne(): void
    {
        $kernel   = $this->dedicatedKernel(autoMapping: true);
        $registry = $this->registry($kernel);
        $legal    = $this->manager($kernel, 'legal');
        $host     = $this->manager($kernel, 'default');

        self::assertSame('default', $registry->getDefaultManagerName(), 'the bundle manager never becomes the default one');
        foreach ([LegalDocument::class, LegalPageVersion::class, LegalAcceptance::class] as $class) {
            self::assertTrue($host->getMetadataFactory()->isTransient($class), $class . ' is not mapped on the auto-mapped default manager');
            self::assertSame($legal, $registry->getManagerForClass($class));
        }
        foreach ([LegalPageVersions::class, LegalDocumentAdminController::class, SeedCommand::class] as $service) {
            self::assertSame($legal, (fn (): ?object => $this->entityManager)->call($this->service($kernel, $service)), $service);
        }
        (new SchemaTool($legal))->createSchema($legal->getMetadataFactory()->getAllMetadata());

        // A pending host entity stays pending; the bundle writes on its own manager and connection.
        $user = new User('pending@example.com');
        $host->persist($user);
        $this->service($kernel, LegalPageVersionsInterface::class)->publishVersion('terms', true, LegalPageVersion::SOURCE_EDITOR);
        $this->service($kernel, LegalDocumentPortabilityInterface::class)->apply([['slug' => 'notice', 'locale' => 'en', 'title' => 'Notice', 'body' => '<p>A</p>']], new LegalActorId('acc-1'), true);
        $manager = $this->service($kernel, LegalAcceptanceManagerInterface::class);
        $manager->record('u-ann', $manager->pendingFor('u-ann', 'en'));

        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($user));
        self::assertSame(0, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM `user`'));
        self::assertSame(1, (int) $legal->getConnection()->fetchOne('SELECT COUNT(*) FROM legal_page_version'));
        self::assertSame(1, (int) $legal->getConnection()->fetchOne('SELECT COUNT(*) FROM legal_document'));
        self::assertSame(2, (int) $legal->getConnection()->fetchOne('SELECT COUNT(*) FROM legal_acceptance'));
        self::assertNotContains('legal_document', $host->getConnection()->createSchemaManager()->listTableNames());
    }

    /**
     * LG-LK-M1: a failed write closes the bundle's own manager; in a kept-alive kernel without the services resetter
     * the next request through http_kernel recovers it, and the host manager is left alone.
     */
    public function testTheOwnManagerIsRecoveredAtTheNextRequestInAWorker(): void
    {
        $kernel = $this->dedicatedKernel(autoMapping: false);
        $legal  = $this->manager($kernel, 'legal');
        $host   = $this->manager($kernel, 'default');
        (new SchemaTool($legal))->createSchema($legal->getMetadataFactory()->getAllMetadata());
        $versions = $this->service($kernel, LegalPageVersionsInterface::class);
        $taken    = $versions->publishVersion('terms', true, LegalPageVersion::SOURCE_EDITOR)->version;
        try {
            $versions->publishVersion('terms', true, LegalPageVersion::SOURCE_EDITOR, [], null, $taken);
            self::fail('Expected the duplicate version.');
        } catch (UniqueConstraintViolationException) {
            self::assertFalse($legal->isOpen(), 'the failed flush closed the bundle manager');
        }
        $user = new User('pending@example.com');
        $host->persist($user);

        // The http_kernel service directly: Kernel::handle() would run the services resetter between requests.
        $httpKernel = $this->testContainer($kernel)->get('http_kernel');
        self::assertInstanceOf(HttpKernel::class, $httpKernel);
        foreach ([1, 2] as $ignored) {
            $request  = Request::create('/no-such-page');
            $response = $httpKernel->handle($request);
            $httpKernel->terminate($request, $response);
        }

        self::assertTrue($this->manager($kernel, 'legal')->isOpen(), 'recovered at the request boundary');
        self::assertSame($taken + 1, $versions->publishVersion('terms', true, LegalPageVersion::SOURCE_EDITOR)->version, 'the services keep working');
        self::assertSame($host, $this->manager($kernel, 'default'), 'the host manager is never reset');
        self::assertTrue($host->contains($user), 'nor cleared');
        $host->flush();
        $httpKernel->terminate($request, $httpKernel->handle($request));
        self::assertTrue($host->contains($user), 'a flushed, managed host entity stays managed across a request');
    }

    /**
     * LG-LK-D-M1: the host's default manager (Flex single-manager shorthand) is never taken as the bundle's own.
     */
    public function testTheDefaultManagerCannotBeTheBundleManager(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_legal_kit.doctrine.entity_manager is "default", your default Doctrine entity manager.');
        $this->versioningKernel(['doctrine' => ['entity_manager' => 'default', 'blame' => 'actor_id']]);
    }

    /**
     * LG-LK-D-M1: a manager that also maps host entities is shared, not the bundle's own: the build fails, so the
     * subscriber never clears or resets it.
     */
    public function testASharedManagerThatMapsHostEntitiesIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The Doctrine entity manager "legal" (nowo_legal_kit.doctrine.entity_manager) also maps Nowo\LegalKitBundle\Tests\Support\Entity.');
        $this->versioningKernel(['doctrine' => ['entity_manager' => 'legal', 'blame' => 'actor_id']], [], [
            'doctrine' => [
                'dbal' => ['connections' => ['legal' => ['url' => 'sqlite:///:memory:']]],
                'orm'  => [
                    'default_entity_manager' => 'default',
                    'entity_managers'        => [
                        'default' => ['connection' => 'default'],
                        'legal'   => ['connection' => 'legal', 'mappings' => ['HostOnLegal' => [
                            'type'      => 'attribute',
                            'is_bundle' => false,
                            'dir'       => dirname(__DIR__) . '/Support/Entity',
                            'prefix'    => 'Nowo\LegalKitBundle\Tests\Support\Entity',
                        ]]],
                    ],
                ],
            ],
        ]);
    }

    public function testADedicatedManagerWithRelationBlameFailsTheBuild(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('needs nowo_legal_kit.doctrine.blame: actor_id or none');
        $this->versioningKernel(['doctrine' => ['entity_manager' => 'legal']]);
    }

    public function testDedicatedManagerWithoutDefaultEntityManagerKey(): void
    {
        $kernel = $this->dedicatedKernel(autoMapping: false, defaultKey: false);

        self::assertSame('default', $this->registry($kernel)->getDefaultManagerName());
        self::assertFalse($this->manager($kernel, 'legal')->getMetadataFactory()->isTransient(LegalDocument::class));
        self::assertTrue($this->manager($kernel, 'default')->getMetadataFactory()->isTransient(LegalDocument::class));
    }

    public function testAnUnknownDedicatedManagerFailsTheBuild(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_legal_kit.doctrine.entity_manager is "missing", but no such Doctrine entity manager is configured (known: "default")');
        $this->versioningKernel(['doctrine' => ['entity_manager' => 'missing', 'blame' => 'none']]);
    }

    /**
     * Kept-alive kernel (no services resetter): a rolled-back host transaction leaves nothing pending in the
     * after-commit dispatcher; the next record dispatches normally, and memory stays flat over many records.
     */
    public function testAfterCommitStateDoesNotLeakAcrossUnitsOfWork(): void
    {
        $kernel     = $this->acceptanceKernel();
        $manager    = $this->service($kernel, LegalAcceptanceManagerInterface::class);
        $events     = $this->listen($kernel, [LegalAcceptedEvent::class]);
        $connection = $this->entityManager($kernel)->getConnection();

        $connection->beginTransaction();
        $manager->record('u-1', $manager->pendingFor('u-1', 'en'));
        $connection->rollBack();
        $manager->record('u-2', $manager->pendingFor('u-2', 'en'));
        self::assertSame([LegalAcceptedEvent::class . ':u-2', LegalAcceptedEvent::class . ':u-2'], $events->getArrayCopy());

        $manager->record('warm', $manager->pendingFor('warm', 'en'));
        $before = memory_get_usage();
        for ($i = 0; $i < 300; ++$i) {
            $connection->beginTransaction();
            $manager->record('loop-' . $i, $manager->pendingFor('loop-' . $i, 'en'));
            $i % 2 === 0 ? $connection->commit() : $connection->rollBack();
            $this->service($kernel, LegalPendingEventsInterface::class)->dispatchPending();
        }
        self::assertLessThan(4 * 1024 * 1024, memory_get_usage() - $before);
        self::assertCount(2 + 2 + 300, $events);
        self::assertSame(0, $this->service($kernel, LegalPendingEventsInterface::class)->countPending(), 'nothing left pending');
    }

    private function dedicatedKernel(bool $autoMapping, bool $defaultKey = true): TestKernel
    {
        $orm = [
            'entity_managers' => [
                'default' => ['connection' => 'default', 'auto_mapping' => $autoMapping],
                'legal'   => ['connection' => 'legal'],
            ],
        ];
        if ($defaultKey) {
            $orm['default_entity_manager'] = 'default';
        }

        return $this->versioningKernel([
            'doctrine'   => ['entity_manager' => 'legal', 'blame' => 'actor_id'],
            'acceptance' => ['enabled' => true, 'enforce' => true, 'subject_resolver' => 'test.legal_kit.subject_resolver'],
        ], ['app_routes'], [
            'doctrine' => ['dbal' => ['connections' => ['legal' => ['url' => 'sqlite:///:memory:']]], 'orm' => $orm],
        ]);
    }

    /**
     * @param list<class-string> $classes
     *
     * @return ArrayObject<int, mixed>
     */
    private function listen(TestKernel $kernel, array $classes): ArrayObject
    {
        $events     = new ArrayObject();
        $dispatcher = $this->service($kernel, EventDispatcherInterface::class);
        foreach ($classes as $class) {
            $dispatcher->addListener($class, static function (object $event) use ($events): void {
                $events->append(match (true) {
                    $event instanceof LegalDocumentsImportedEvent                                       => $event->documents,
                    $event instanceof LegalAcceptedEvent, $event instanceof LegalAcceptancesErasedEvent => $event::class . ':' . $event->subjectId,
                    default                                                                             => $event::class,
                });
            });
        }

        return $events;
    }

    private function registry(TestKernel $kernel): ManagerRegistry
    {
        $registry = $this->testContainer($kernel)->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return $registry;
    }

    private function manager(TestKernel $kernel, string $name): EntityManagerInterface
    {
        $manager = $this->registry($kernel)->getManager($name);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }
}
