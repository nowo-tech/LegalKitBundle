<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Functional;

use Nowo\LegalKitBundle\Acceptance\Entity\LegalAcceptance;
use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceManager;
use Nowo\LegalKitBundle\Acceptance\StaleLegalOfferException;
use Nowo\LegalKitBundle\Tests\Support\TestKernel;
use Nowo\LegalKitBundle\Tests\Support\VersioningTestCase;
use Nowo\LegalKitBundle\Versioning\Entity\LegalPageVersion;
use Nowo\LegalKitBundle\Versioning\LegalPageVersions;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;

use function array_map;

/**
 * 1.14 (REQ-RUNTIME-003, acceptances are evidence): an acceptance is never recorded against a version the database no
 * longer holds as current. The offer's version can come from a stale cached requirements map (a version committed on
 * another server, or before the cache delete that follows the commit), while its text hash comes from the committed
 * text: before 1.14 that could record version N with the hash of the text of N+1.
 */
final class AcceptanceStaleOfferTest extends VersioningTestCase
{
    public function testAnOfferFromAStaleCachedMapRecordsNothingAndTheCurrentOfferIsShown(): void
    {
        [$kernel, $client, $crawler] = $this->offered();
        $versions                    = $this->service($kernel, LegalPageVersions::class);

        // Committed elsewhere: the cached map still says version 1 (no invalidation yet).
        $versions->publishVersion('terms', true, 'editor', ['en' => 'New rules.'], flush: false);
        self::assertSame(1, $versions->current('terms'), 'the cached map is stale');

        $client->submit($this->ticked($crawler));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-testid="nowo-legal-kit-acceptance-stale"', $html);
        self::assertStringContainsString('Version 2', $html, 'the current offer, read after dropping the cached map');
        self::assertSame([], $this->records($kernel), 'nothing of the stale offer is recorded (not even the unchanged page)');
        self::assertSame(2, $versions->current('terms'));

        $client->submit($this->ticked(new Crawler($html, 'http://localhost/legal/accept')));
        self::assertSame(303, $client->getResponse()->getStatusCode());
        self::assertSame([['privacy', 1], ['terms', 2]], array_map(static fn (LegalAcceptance $a): array => [$a->getSlug(), $a->getVersion()], $this->records($kernel)));
    }

    public function testAStaleOfferWithNothingPendingAnyMoreSendsTheUserOn(): void
    {
        $kernel   = $this->acceptanceKernel(['acceptance' => ['cache_pool' => 'cache.app']]);
        $versions = $this->service($kernel, LegalPageVersions::class);
        $manager  = $this->service($kernel, LegalAcceptanceManager::class);
        $this->clearCache($kernel);
        $manager->record('u-admin', $manager->pendingFor('u-admin', 'en'));
        $versions->publish('terms', true, 'editor', ['en' => 'New rules.']);
        $client = $this->adminClient($kernel);
        $client->setServerParameter('HTTP_ACCEPT', 'text/html,application/xhtml+xml');
        $crawler = $client->request('GET', '/legal/accept');
        self::assertStringContainsString('Version 2', (string) $client->getResponse()->getContent());

        // An operator removed version 2 directly in the database (the cached map still names it).
        $entityManager = $this->entityManager($kernel);
        $entityManager->getConnection()->delete($entityManager->getClassMetadata(LegalPageVersion::class)->getTableName(), ['slug' => 'terms', 'version' => 2]);

        $client->submit($this->ticked($crawler));
        self::assertSame(303, $client->getResponse()->getStatusCode(), 'nothing is pending any more');
        self::assertSame([['privacy', 1], ['terms', 1]], array_map(static fn (LegalAcceptance $a): array => [$a->getSlug(), $a->getVersion()], $this->records($kernel)));
    }

    public function testTheManagerRefusesAStaleBatchWhole(): void
    {
        $kernel   = $this->acceptanceKernel(['acceptance' => ['cache_pool' => 'cache.app']]);
        $versions = $this->service($kernel, LegalPageVersions::class);
        $manager  = $this->service($kernel, LegalAcceptanceManager::class);
        $this->clearCache($kernel);
        $pending = $manager->pendingFor('u-ann', 'en');
        $versions->publishVersion('terms', true, 'editor', flush: false);
        self::assertSame(1, $versions->current('terms'), 'the cached map is stale');

        try {
            $manager->record('u-ann', $pending);
            self::fail('stale');
        } catch (StaleLegalOfferException $e) {
            self::assertSame(['terms', 1, 2], [$e->slug, $e->offeredVersion, $e->currentVersion]);
            self::assertStringContainsString('nothing was recorded', $e->getMessage());
        }
        self::assertSame(2, $versions->current('terms'), 'UJ-LK-1: the stale cached map was dropped');
        self::assertSame(2, $manager->pendingFor('u-ann', 'en')[1]->currentVersion, 'a second pendingFor() gets the current offer');
        $connection = $this->entityManager($kernel)->getConnection();
        $connection->beginTransaction();
        try {
            $manager->recordInTransaction('u-ann', $pending);
            self::fail('stale');
        } catch (StaleLegalOfferException) {
            $connection->rollBack();
        }
        self::assertSame([], $this->records($kernel));
        self::assertSame(0, $manager->record('u-ann', []), 'an empty batch reads nothing');
    }

    /**
     * An acceptance kernel with the cached map, and the GET of the acceptance page (privacy + terms at version 1).
     *
     * @return array{0: TestKernel, 1: KernelBrowser, 2: Crawler}
     */
    private function offered(): array
    {
        $kernel = $this->acceptanceKernel(['acceptance' => ['cache_pool' => 'cache.app']]);
        $this->clearCache($kernel);
        $client = $this->adminClient($kernel);
        $client->setServerParameter('HTTP_ACCEPT', 'text/html,application/xhtml+xml');
        $crawler = $client->request('GET', '/legal/accept');
        self::assertSame(200, $client->getResponse()->getStatusCode());

        return [$kernel, $client, $crawler];
    }

    private function ticked(Crawler $crawler): Form
    {
        $form = $crawler->filter('form[name="nowo_legal_kit_acceptance"]')->form();
        self::tick($form, 'nowo_legal_kit_acceptance[accept_terms]');

        return $form;
    }

    private function clearCache(TestKernel $kernel): void
    {
        $pool = $this->testContainer($kernel)->get('cache.app');
        self::assertInstanceOf(AdapterInterface::class, $pool);
        $pool->clear();
    }

    /**
     * @return list<LegalAcceptance>
     */
    private function records(TestKernel $kernel): array
    {
        $manager = $this->entityManager($kernel);
        $manager->clear();

        return $manager->getRepository(LegalAcceptance::class)->findBy([], ['slug' => 'ASC', 'version' => 'ASC']);
    }
}
