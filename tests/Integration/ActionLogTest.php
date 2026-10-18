<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Integration;

use Nowo\LegalKitBundle\EventListener\ActionLogListener;
use Nowo\LegalKitBundle\Tests\Support\Entity\ActionLog;
use Nowo\LegalKitBundle\Tests\Support\KernelTestCase;

/**
 * With the AuditKit action log enabled, saves, restores and imports are recorded.
 */
final class ActionLogTest extends KernelTestCase
{
    public function testEditsAreRecorded(): void
    {
        $kernel = $this->bootKernel([], [], true, ['nowo_audit_kit' => ['action_log' => ['entity_class' => ActionLog::class]]]);
        $client = $this->adminClient($kernel);

        $crawler = $client->request('GET', '/admin/legal/terms/en');
        $client->submit($crawler->filter('form[name="nowo_legal_kit_document"]')->form());
        $crawler = $client->request('GET', '/admin/legal/terms/en');
        $client->submit($crawler->filter('form[name="nowo_legal_kit_reset"]')->form());

        $logs = $this->entityManager($kernel)->getRepository(ActionLog::class)->findBy([], ['id' => 'ASC']);
        self::assertSame([ActionLogListener::ACTION_SAVED, ActionLogListener::ACTION_RESTORED], array_map(static fn (ActionLog $log): string => (string) $log->getAction(), $logs));
        self::assertSame(['slug' => 'terms', 'locale' => 'en'], $logs[0]->getContext());
    }
}
