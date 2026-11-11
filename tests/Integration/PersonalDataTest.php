<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Integration;

use ArrayObject;
use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceBatch;
use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceManager;
use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceManagerInterface;
use Nowo\LegalKitBundle\Doctrine\LegalPendingEventsInterface;
use Nowo\LegalKitBundle\Event\LegalAcceptancesErasedEvent;
use Nowo\LegalKitBundle\PersonalData\AcceptanceSubjectPersonalDataResolver;
use Nowo\LegalKitBundle\PersonalData\LegalAcceptancesPersonalDataEraser;
use Nowo\LegalKitBundle\PersonalData\LegalAcceptancesPersonalDataExportProvider;
use Nowo\LegalKitBundle\PersonalData\LegalPersonalDataSubjectResolverInterface;
use Nowo\LegalKitBundle\PersonalData\PersonalDataSubjects;
use Nowo\LegalKitBundle\Tests\Support\Entity\User;
use Nowo\LegalKitBundle\Tests\Support\TestKernel;
use Nowo\LegalKitBundle\Tests\Support\VersioningTestCase;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraser;
use Nowo\UserKitBundle\PersonalData\PersonalDataExporter;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function array_column;
use function array_keys;
use function gc_collect_cycles;
use function json_encode;
use function memory_get_usage;
use function str_repeat;

use const JSON_THROW_ON_ERROR;

/**
 * 1.11 GDPR integration: the UserKit `legal_acceptances` eraser and export section, the subject SPI, the transaction /
 * event semantics of {@see LegalAcceptanceManagerInterface::eraseFor()} and worker mode (kernel kept alive).
 */
final class PersonalDataTest extends VersioningTestCase
{
    public function testUserKitErasesAndExportsTheAcceptancesOfTheUser(): void
    {
        $kernel  = $this->acceptanceKernel([], ['app_routes', 'user_kit']);
        $manager = $this->service($kernel, LegalAcceptanceManagerInterface::class);
        $events  = $this->erasedEvents($kernel);
        $manager->record('u-ann', $manager->pendingFor('u-ann', 'en'));
        $manager->record('u-bob', $manager->pendingFor('u-bob', 'en'));
        $exporter = $this->service($kernel, PersonalDataExporter::class);
        $eraser   = $this->service($kernel, PersonalDataEraser::class);
        $ann      = new User('ann');
        $bob      = new User('bob');

        $section = $exporter->export($ann)['sections']['legal_acceptances'] ?? null;
        self::assertIsArray($section);
        self::assertSame(2, $section['count']);
        self::assertIsArray($section['entries']);
        self::assertSame(['privacy', 'terms'], array_column($section['entries'], 'page'));
        self::assertIsArray($section['entries'][0]);
        self::assertSame(['page', 'version', 'locale', 'kind', 'accepted_at', 'content_hash'], array_keys($section['entries'][0]));
        self::assertSame($manager->exportFor('u-ann'), $section['entries'], 'the port shape');
        self::assertStringNotContainsString('u-ann', json_encode($section, JSON_THROW_ON_ERROR), 'no subject id in the section');

        self::assertSame(['legal_acceptances'], $eraser->erase($ann));
        self::assertSame(['count' => 0, 'entries' => []], $exporter->export($ann)['sections']['legal_acceptances'] ?? null);
        self::assertSame(2, $exporter->export($bob)['sections']['legal_acceptances']['count'] ?? null, 'another user keeps his records');
        $this->service($kernel, LegalPendingEventsInterface::class)->dispatchPending();
        self::assertSame(['u-ann'], $events->getArrayCopy(), 'after the commit of the UserKit transaction');

        // Idempotent: a second run finds nothing and does not fail.
        self::assertSame(['legal_acceptances'], $eraser->erase($ann));
        $this->service($kernel, LegalPendingEventsInterface::class)->dispatchPending();
        self::assertSame(['u-ann', 'u-ann'], $events->getArrayCopy());
        self::assertCount(2, $manager->exportFor('u-bob'));

        // A user the host resolver leaves out (null) or that is not a security user has no subject.
        self::assertSame(['count' => 0, 'entries' => []], $exporter->export(new User('robot'))['sections']['legal_acceptances'] ?? null);
        $adapter = $this->service($kernel, LegalPersonalDataSubjectResolverInterface::class);
        self::assertInstanceOf(AcceptanceSubjectPersonalDataResolver::class, $adapter);
        self::assertSame([], $adapter->subjectsFor(new stdClass(), null));
        self::assertSame(['u-ann'], $adapter->subjectsFor(new InMemoryUser('ann', null), 'ann'));
    }

    public function testAHostSubjectResolverWithSeveralIds(): void
    {
        $kernel = $this->acceptanceKernel(
            ['personal_data' => ['subject_resolver' => 'test.legal_kit.personal_data_subjects']],
            ['app_routes', 'user_kit', 'subject_spi'],
        );
        $manager = $this->service($kernel, LegalAcceptanceManagerInterface::class);
        $events  = $this->erasedEvents($kernel);
        foreach (['u-ann', 'old-ann', 'u-bob'] as $subject) {
            $manager->record($subject, $manager->pendingFor($subject, 'en'));
        }

        self::assertSame(4, $this->service($kernel, PersonalDataExporter::class)->export(new User('ann'))['sections']['legal_acceptances']['count'] ?? null);
        $this->service($kernel, PersonalDataEraser::class)->erase(new User('ann'));
        $this->service($kernel, LegalPendingEventsInterface::class)->dispatchPending();

        self::assertSame(['u-ann', 'old-ann'], $events->getArrayCopy(), 'once per distinct storable id');
        self::assertSame([], $manager->exportFor('old-ann'));
        self::assertCount(2, $manager->exportFor('u-bob'));
    }

    public function testTheAdaptersCallThePortOncePerDistinctSubject(): void
    {
        $port = new class implements LegalAcceptanceManagerInterface {
            /** @var list<string> */
            public array $calls = [];

            public function pendingFor(string $subjectId, ?string $locale = null): array
            {
                return [];
            }

            public function record(string $subjectId, array $pages): int
            {
                return 0;
            }

            public function recordInTransaction(string $subjectId, array $pages): LegalAcceptanceBatch
            {
                return new LegalAcceptanceBatch($subjectId, []);
            }

            public function afterCommit(LegalAcceptanceBatch ...$batches): void
            {
            }

            public function exportFor(string $subjectId): array
            {
                $this->calls[] = 'export:' . $subjectId;

                return [['page' => 'terms', 'version' => 1, 'locale' => 'en', 'kind' => 'accept', 'accepted_at' => '2026-01-01T00:00:00+00:00', 'content_hash' => str_repeat('a', 64)]];
            }

            public function eraseFor(string $subjectId): int
            {
                $this->calls[] = 'erase:' . $subjectId;

                return 1;
            }
        };
        $subjects = new class implements LegalPersonalDataSubjectResolverInterface {
            public function subjectsFor(object $user, ?string $originalIdentifier): array
            {
                return ['a', 'b', 'a', '1', '', "a\0", str_repeat('x', 129), 'orig:' . ($originalIdentifier ?? '-')];
            }
        };
        $eraser   = new LegalAcceptancesPersonalDataEraser($port, $subjects);
        $provider = new LegalAcceptancesPersonalDataExportProvider($port, $subjects);
        $user     = new InMemoryUser('ann', null);

        self::assertSame('legal_acceptances', $eraser->getKey());
        self::assertSame('legal_acceptances', $provider->getSectionKey());
        self::assertTrue($eraser->supports($user));
        self::assertTrue($provider->supports(new stdClass()));
        $eraser->erase(new ErasureRequest($user, null, [], 'ann-before'));
        self::assertSame(4, $provider->export($user)['count']);
        self::assertSame(
            ['erase:a', 'erase:b', 'erase:1', 'erase:orig:ann-before', 'export:a', 'export:b', 'export:1', 'export:orig:-'],
            $port->calls,
        );
        self::assertSame(['1', 'x'], PersonalDataSubjects::distinct(['1', 'x', '1']));
    }

    public function testDisabledLeavesUserKitAlone(): void
    {
        $kernel  = $this->acceptanceKernel(['personal_data' => ['enabled' => false]], ['app_routes', 'user_kit']);
        $manager = $this->service($kernel, LegalAcceptanceManagerInterface::class);
        $manager->record('u-ann', $manager->pendingFor('u-ann', 'en'));

        self::assertArrayNotHasKey('legal_acceptances', $this->service($kernel, PersonalDataExporter::class)->export(new User('ann'))['sections']);
        self::assertSame([], $this->service($kernel, PersonalDataEraser::class)->erase(new User('ann')));
        self::assertCount(2, $manager->exportFor('u-ann'));
        self::assertFalse($this->testContainer($kernel)->has(LegalPersonalDataSubjectResolverInterface::class));
    }

    public function testEraseOpensItsOwnTransactionAndDispatchesAfterItsCommit(): void
    {
        $kernel     = $this->acceptanceKernel();
        $manager    = $this->service($kernel, LegalAcceptanceManagerInterface::class);
        $connection = $this->entityManager($kernel)->getConnection();
        /** @var ArrayObject<int, list<bool|int|string>> $active */
        $active = new ArrayObject();
        $this->service($kernel, EventDispatcherInterface::class)->addListener(LegalAcceptancesErasedEvent::class, static function (LegalAcceptancesErasedEvent $event) use ($connection, $active): void {
            $active->append([$event->subjectId, $event->count, $connection->isTransactionActive(), (int) $connection->fetchOne('SELECT COUNT(*) FROM legal_acceptance')]);
        });
        $manager->record('u-ann', $manager->pendingFor('u-ann', 'en'));

        self::assertSame(2, $manager->eraseFor('u-ann'));
        self::assertSame([['u-ann', 2, false, 0]], $active->getArrayCopy(), 'dispatched at once, after the commit');
        self::assertFalse($connection->isTransactionActive());

        // Inside the caller's transaction: nothing until its outermost commit.
        $manager->record('u-ann', $manager->pendingFor('u-ann', 'en'));
        $connection->beginTransaction();
        self::assertSame(2, $manager->eraseFor('u-ann'));
        self::assertCount(1, $active);
        $connection->commit();
        $this->service($kernel, LegalPendingEventsInterface::class)->dispatchPending();
        self::assertSame(['u-ann', 2, false, 0], $active[1]);

        // Ids that can never be stored: no query, no event.
        foreach (['', "u-ann\0", str_repeat('a', 129), "\xff"] as $invalid) {
            self::assertFalse(LegalAcceptanceManager::isStorableSubjectId($invalid));
            self::assertSame(0, $manager->eraseFor($invalid));
            self::assertSame([], $manager->exportFor($invalid));
        }
        self::assertTrue(LegalAcceptanceManager::isStorableSubjectId(str_repeat('é', 128)));
        self::assertCount(2, $active);
    }

    /**
     * Worker mode (FrankenPHP, kernel kept alive, no reset): the same service instances erase and export different
     * users many times; no state leaks between users and memory stays flat.
     */
    public function testWorkerModeManyRunsInOneKernel(): void
    {
        $kernel   = $this->acceptanceKernel([], ['app_routes', 'user_kit']);
        $manager  = $this->service($kernel, LegalAcceptanceManagerInterface::class);
        $exporter = $this->service($kernel, PersonalDataExporter::class);
        $eraser   = $this->service($kernel, PersonalDataEraser::class);
        $pending  = $this->service($kernel, LegalPendingEventsInterface::class);
        $manager->record('u-ann', $manager->pendingFor('u-ann', 'en'));
        $manager->record('u-bob', $manager->pendingFor('u-bob', 'en'));
        $pages = $manager->pendingFor('u-carl', 'en');
        $ann   = new User('ann');
        $bob   = new User('bob');
        $carl  = new User('carl');
        $snapA = $exporter->export($ann)['sections']['legal_acceptances'] ?? null;
        $snapB = $exporter->export($bob)['sections']['legal_acceptances'] ?? null;
        self::assertSame(2, $snapA['count'] ?? null);

        $baseline = 0;
        for ($i = 0; $i < 1000; ++$i) {
            self::assertSame(2, $manager->record('u-carl', $pages));
            self::assertSame(['legal_acceptances'], $eraser->erase($carl));
            $pending->dispatchPending();
            self::assertSame([], $manager->exportFor('u-carl'));
            self::assertSame($snapA, $exporter->export($ann)['sections']['legal_acceptances'] ?? null);
            self::assertSame($snapB, $exporter->export($bob)['sections']['legal_acceptances'] ?? null);
            if ($i === 99) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }
        gc_collect_cycles();
        self::assertLessThan(1024 * 1024, memory_get_usage() - $baseline, 'flat memory over 900 runs');
        self::assertSame(0, $pending->countPending());
        self::assertSame(0, $this->entityManager($kernel)->getUnitOfWork()->size(), 'nothing kept in the identity map');
    }

    /**
     * @return ArrayObject<int, string> Subject ids of the {@see LegalAcceptancesErasedEvent}s
     */
    private function erasedEvents(TestKernel $kernel): ArrayObject
    {
        /** @var ArrayObject<int, string> $events */
        $events = new ArrayObject();
        $this->service($kernel, EventDispatcherInterface::class)->addListener(LegalAcceptancesErasedEvent::class, static function (LegalAcceptancesErasedEvent $event) use ($events): void {
            $events->append($event->subjectId);
        });

        return $events;
    }
}
