<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Integration;

use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceManager;
use Nowo\LegalKitBundle\Event\LegalDocumentRebasedEvent;
use Nowo\LegalKitBundle\EventListener\ActionLogListener;
use Nowo\LegalKitBundle\Tests\Support\Entity\ActionLog;
use Nowo\LegalKitBundle\Tests\Support\Entity\User;
use Nowo\LegalKitBundle\Tests\Support\KernelTestCase;
use Nowo\LegalKitBundle\Versioning\LegalPageVersions;
use Nowo\LegalKitBundle\Versioning\LegalSeedUpgrader;
use Psr\EventDispatcher\EventDispatcherInterface;

use function sys_get_temp_dir;

/**
 * With the AuditKit action log enabled, saves, restores and imports are recorded.
 */
final class ActionLogTest extends KernelTestCase
{
    public function testEditsAreRecorded(): void
    {
        $kernel = $this->bootKernel([], [], true, ['nowo_audit_kit' => ['user_class' => User::class, 'action_log' => ['entity_class' => ActionLog::class]]]);
        $client = $this->adminClient($kernel);

        $crawler = $client->request('GET', '/admin/legal/terms/en');
        $client->submit($crawler->filter('form[name="nowo_legal_kit_document"]')->form());
        $crawler = $client->request('GET', '/admin/legal/terms/en');
        $client->submit($crawler->filter('form[name="nowo_legal_kit_reset"]')->form());

        $logs = $this->entityManager($kernel)->getRepository(ActionLog::class)->findBy([], ['id' => 'ASC']);
        self::assertSame([ActionLogListener::ACTION_SAVED, ActionLogListener::ACTION_RESTORED], array_map(static fn (ActionLog $log): string => (string) $log->getAction(), $logs));
        self::assertSame(['slug' => 'terms', 'locale' => 'en'], $logs[0]->getContext());
    }

    public function testVersioningEventsAreRecorded(): void
    {
        $audit  = ['nowo_audit_kit' => ['user_class' => User::class, 'action_log' => ['entity_class' => ActionLog::class]]];
        $config = [
            'locales'    => ['en'],
            'versioning' => ['enabled' => true],
            'acceptance' => ['enabled' => true, 'subject_resolver' => 'test.legal_kit.subject_resolver', 'cache_pool' => null, 'action_log' => true],
            'seed'       => ['snapshots_dir' => sys_get_temp_dir() . '/nowo-legal-kit-action-log-snapshots'],
        ];
        $kernel = $this->bootKernel($config, [], true, $audit);
        $client = $this->adminClient($kernel);

        $crawler = $client->request('GET', '/admin/legal/notice/en');
        $client->submit($crawler->filter('form[name="nowo_legal_kit_document"]')->form());
        $crawler   = $client->request('GET', '/admin/legal/notice/en/compare');
        $container = $this->testContainer($kernel);
        $versions  = $container->get(LegalPageVersions::class);
        self::assertInstanceOf(LegalPageVersions::class, $versions);
        $versions->publish('terms', true, 'editor');
        $manager = $container->get(LegalAcceptanceManager::class);
        self::assertInstanceOf(LegalAcceptanceManager::class, $manager);
        $manager->record('u-ann', $manager->pendingFor('u-ann', 'en'));
        $upgrader = $container->get(LegalSeedUpgrader::class);
        self::assertInstanceOf(LegalSeedUpgrader::class, $upgrader);
        $upgrader->run(null);
        $dispatcher = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->dispatch(new LegalDocumentRebasedEvent('notice', 'en', 'r1'));

        $logs    = $this->entityManager($kernel)->getRepository(ActionLog::class)->findBy([], ['id' => 'ASC']);
        $actions = array_map(static fn (ActionLog $log): string => (string) $log->getAction(), $logs);
        self::assertSame([
            ActionLogListener::ACTION_SAVED,
            ActionLogListener::ACTION_VERSION_PUBLISHED,
            ActionLogListener::ACTION_ACCEPTED,
            ActionLogListener::ACTION_ACCEPTED,
            ActionLogListener::ACTION_SEED_UPGRADED,
            ActionLogListener::ACTION_REBASED,
        ], $actions);
        self::assertSame(['slug' => 'notice', 'locale' => 'en', 'publication' => 'minor', 'version' => 1], $logs[0]->getContext());
        self::assertArrayNotHasKey('subject_id', $logs[2]->getContext(), 'The subject id is never written');
        self::assertCount(1, $crawler);
    }

    public function testAcceptancesAreNotRecordedByDefault(): void
    {
        $audit  = ['nowo_audit_kit' => ['user_class' => User::class, 'action_log' => ['entity_class' => ActionLog::class]]];
        $kernel = $this->bootKernel([
            'locales'    => ['en'],
            'versioning' => ['enabled' => true],
            'acceptance' => ['enabled' => true, 'subject_resolver' => 'test.legal_kit.subject_resolver', 'cache_pool' => null],
        ], [], true, $audit);
        $manager = $this->testContainer($kernel)->get(LegalAcceptanceManager::class);
        self::assertInstanceOf(LegalAcceptanceManager::class, $manager);
        $manager->record('u-ann', $manager->pendingFor('u-ann'));

        self::assertSame([], $this->entityManager($kernel)->getRepository(ActionLog::class)->findAll());
    }
}
