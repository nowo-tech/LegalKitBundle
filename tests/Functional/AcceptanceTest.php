<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Functional;

use Nowo\LegalKitBundle\Acceptance\Entity\LegalAcceptance;
use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceManager;
use Nowo\LegalKitBundle\EventSubscriber\AcceptanceGateSubscriber;
use Nowo\LegalKitBundle\Tests\Support\TestKernel;
use Nowo\LegalKitBundle\Tests\Support\VersioningTestCase;
use Nowo\LegalKitBundle\Versioning\LegalPageVersions;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

use function strlen;

/**
 * Re-acceptance: the gate (HTML navigations only, exemptions, no loops, fail open), the page (accept vs acknowledge,
 * CSRF, stale offers, targets), the records and the personal-data hooks.
 */
final class AcceptanceTest extends VersioningTestCase
{
    private function browser(TestKernel $kernel, string $user = 'admin'): KernelBrowser
    {
        $client = $this->adminClient($kernel, $user);
        $client->setServerParameter('HTTP_ACCEPT', 'text/html,application/xhtml+xml');
        $client->request('GET', '/app/skip'); // starts the session (exempt route)

        return $client;
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

    public function testFlow(): void
    {
        $kernel = $this->acceptanceKernel(['acceptance' => ['exempt_routes' => ['app_skip']]]);
        $client = $this->browser($kernel);

        $client->request('GET', '/app?tab=2');
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('/legal/accept', $client->getResponse()->headers->get('Location'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        $crawler = $client->followRedirect();
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-testid="nowo-legal-kit-acceptance-terms"', $html);
        self::assertStringContainsString('data-nowo-legal-kind="acknowledge"', $html);
        self::assertStringContainsString('name="nowo_legal_kit_acceptance[accept_terms]"', $html);
        self::assertStringNotContainsString('accept_privacy', $html, 'Privacy is acknowledged, never accepted');
        self::assertStringContainsString('We have updated our Privacy policy.', $html);
        self::assertStringContainsString('href="/legal/terms" target="_blank" rel="noopener"', $html);
        self::assertStringContainsString('Accept and continue', $html);
        self::assertStringNotContainsString('Sign out', $html, 'No logout configured');
        self::assertStringNotContainsString('checked', $html, 'Nothing is pre-ticked');

        $form = $crawler->filter('form[name="nowo_legal_kit_acceptance"]')->form();
        $client->submit($form);
        self::assertSame(422, $client->getResponse()->getStatusCode(), 'Terms must be ticked');
        self::assertSame([], $this->records($kernel));

        self::tick($form, 'nowo_legal_kit_acceptance[accept_terms]');
        $client->submit($form);
        self::assertSame(303, $client->getResponse()->getStatusCode());
        self::assertSame('/app?tab=2', $client->getResponse()->headers->get('Location'));
        $records = $this->records($kernel);
        self::assertCount(2, $records);
        self::assertSame(['privacy', 1, 'en', 'acknowledge', 'u-admin'], [$records[0]->getSlug(), $records[0]->getVersion(), $records[0]->getLocale(), $records[0]->getKind(), $records[0]->getSubjectId()]);
        self::assertSame(['terms', 'accept'], [$records[1]->getSlug(), $records[1]->getKind()]);
        self::assertSame(64, strlen($records[1]->getContentHash()));

        $client->request('GET', '/app');
        self::assertSame(200, $client->getResponse()->getStatusCode());

        // Nothing pending: the page sends the user on.
        $client->request('GET', '/legal/accept');
        self::assertSame('/', $client->getResponse()->headers->get('Location'));

        // A new version that users must accept: back to the page, with the summary.
        $this->service($kernel, LegalPageVersions::class)->publish('terms', true, 'editor', ['en' => "New rules <b>here</b>.\nLine two."]);
        $client->request('GET', '/app');
        self::assertSame('/legal/accept', $client->getResponse()->headers->get('Location'));
        $crawler = $client->followRedirect();
        $html    = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('New rules &lt;b&gt;here&lt;/b&gt;.<br />', $html);
        self::assertStringContainsString('Version 2', $html);
        self::assertStringNotContainsString('data-testid="nowo-legal-kit-acceptance-privacy"', $html, 'Privacy is still satisfied');

        // The offer changes while the user reads it: nothing is recorded.
        $form = $crawler->filter('form[name="nowo_legal_kit_acceptance"]')->form();
        self::tick($form, 'nowo_legal_kit_acceptance[accept_terms]');
        $this->service($kernel, LegalPageVersions::class)->publish('terms', true, 'editor');
        $client->submit($form);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('data-testid="nowo-legal-kit-acceptance-stale"', (string) $client->getResponse()->getContent());
        self::assertCount(2, $this->records($kernel));

        $crawler = $client->request('GET', '/legal/accept');
        $form    = $crawler->filter('form[name="nowo_legal_kit_acceptance"]')->form();
        self::tick($form, 'nowo_legal_kit_acceptance[accept_terms]');
        $client->submit($form);
        self::assertSame(303, $client->getResponse()->getStatusCode());
        self::assertSame('/app', $client->getResponse()->headers->get('Location'), 'The target stored by the gate survives a stale offer');
        self::assertSame([1, 3], array_map(static fn (LegalAcceptance $a): int => $a->getVersion(), array_values(array_filter($this->records($kernel), static fn (LegalAcceptance $a): bool => $a->getSlug() === 'terms'))));

        // CSRF.
        $this->service($kernel, LegalPageVersions::class)->publish('terms', true, 'editor');
        $client->request('POST', '/legal/accept', ['nowo_legal_kit_acceptance' => ['accept_terms' => '1', 'offer' => 'x', '_token' => 'forged']]);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertCount(3, $this->records($kernel));
    }

    public function testExemptionsAndNonHtmlRequests(): void
    {
        $kernel = $this->acceptanceKernel(['acceptance' => ['exempt_routes' => ['app_skip'], 'exempt_paths' => ['/api']]]);
        $client = $this->browser($kernel);

        foreach (['/legal/terms', '/es/legal/privacy', '/app/mfa', '/api/skip', '/app/skip', '/admin/legal', '/legal/accept'] as $path) {
            $client->request('GET', $path);
            self::assertNotSame('/legal/accept', $client->getResponse()->headers->get('Location'), $path);
        }
        $client->request('GET', '/app/data', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame(200, $client->getResponse()->getStatusCode(), 'JSON passes');
        $client->request('GET', '/app', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertSame(200, $client->getResponse()->getStatusCode(), 'XHR passes');
        $client->request('POST', '/app');
        self::assertSame(200, $client->getResponse()->getStatusCode(), 'Writes pass');
        $client->request('GET', '/es/legal/accept');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Textos legales actualizados', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('href="/es/legal/terms"', (string) $client->getResponse()->getContent());

        // No session cookie (API key style): never redirected.
        $fresh = $this->adminClient($kernel);
        $fresh->request('GET', '/app', server: ['HTTP_ACCEPT' => 'text/html']);
        self::assertSame(200, $fresh->getResponse()->getStatusCode());
    }

    public function testNonHtmlDeny(): void
    {
        $kernel = $this->acceptanceKernel(['acceptance' => ['non_html' => 'deny', 'exempt_routes' => ['app_skip']]]);
        $client = $this->browser($kernel);

        $client->request('GET', '/app/data', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertSame('{"error":"legal_acceptance_required","acceptance_url":"\/legal\/accept"}', $client->getResponse()->getContent());
        $client->request('GET', '/es/app', server: ['HTTP_ACCEPT' => 'text/html']);
        self::assertSame(404, $client->getResponse()->getStatusCode(), 'An unrouted path is not a navigation to gate');
    }

    public function testSubjectsThatAreNotGated(): void
    {
        $kernel = $this->acceptanceKernel(['acceptance' => ['exempt_routes' => ['app_skip']]]);
        foreach (['robot', 'bad', 'boom'] as $user) {
            $client = $this->browser($kernel, $user);
            $client->request('GET', '/app');
            self::assertSame(200, $client->getResponse()->getStatusCode(), $user);
        }

        $client = $this->browser($kernel, 'robot');
        $client->request('GET', '/legal/accept');
        self::assertSame(403, $client->getResponse()->getStatusCode());

        $anonymous = $this->client($kernel);
        $anonymous->request('GET', '/legal/accept');
        self::assertSame(401, $anonymous->getResponse()->getStatusCode());
    }

    public function testTargetsLogoutAndUnavailablePages(): void
    {
        $kernel = $this->acceptanceKernel([
            'acceptance' => ['exempt_routes' => ['app_skip'], 'default_target' => 'app_home', 'logout_route' => 'app_logout'],
            'pages'      => ['extra' => ['acceptance' => 'accept']],
        ]);
        $client  = $this->browser($kernel);
        $crawler = $client->request('GET', '/legal/accept');
        $html    = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('href="/app/logout"', $html);
        self::assertStringNotContainsString('acceptance-extra', $html, 'A page without text cannot be accepted');

        $form = $crawler->filter('form[name="nowo_legal_kit_acceptance"]')->form();
        self::tick($form, 'nowo_legal_kit_acceptance[accept_terms]');
        $client->submit($form);
        self::assertSame('/app', $client->getResponse()->headers->get('Location'), 'default_target');
    }

    public function testCachedRequirementsAndSatisfiedSession(): void
    {
        $kernel = $this->acceptanceKernel(['acceptance' => ['cache_pool' => 'cache.app', 'exempt_routes' => ['app_skip']]]);
        $pool   = $this->testContainer($kernel)->get('cache.app');
        self::assertInstanceOf(AdapterInterface::class, $pool);
        $pool->clear();
        $versions = $this->service($kernel, LegalPageVersions::class);
        $before   = $versions->fingerprint();
        self::assertSame(1, $versions->current('terms'));

        $this->service($kernel, LegalAcceptanceManager::class)->record('u-admin', $this->service($kernel, LegalAcceptanceManager::class)->pendingFor('u-admin', 'en'));
        $client = $this->browser($kernel);
        $client->request('GET', '/app');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $client->request('GET', '/app');
        self::assertSame(200, $client->getResponse()->getStatusCode(), 'Satisfied state cached in the session');

        $versions->publish('privacy', true, 'editor');
        self::assertNotSame($before, $versions->fingerprint(), 'Publishing invalidates the cached map');
        self::assertSame(2, $versions->required('privacy'));
        $client->request('GET', '/app');
        self::assertSame('/legal/accept', $client->getResponse()->headers->get('Location'));
    }

    public function testManagerHooks(): void
    {
        $kernel  = $this->acceptanceKernel();
        $manager = $this->service($kernel, LegalAcceptanceManager::class);

        $pending = $manager->pendingFor('u-ann', 'xx');
        self::assertSame(['privacy', 'terms'], array_map(static fn ($p): string => $p->page->slug, $pending));
        self::assertNull($pending[1]->changeSummary);
        self::assertSame(2, $manager->record('u-ann', $pending));
        self::assertSame(0, $manager->record('u-ann', $pending), 'Insert-only and idempotent');
        self::assertSame([], $manager->pendingFor('u-ann'));
        $manager->record('u-bob', $manager->pendingFor('u-bob'));

        $export = $manager->exportFor('u-ann');
        self::assertCount(2, $export);
        self::assertSame(['page', 'version', 'locale', 'kind', 'accepted_at', 'content_hash'], array_keys($export[0]));
        self::assertSame(2, $manager->eraseFor('u-ann'));
        self::assertSame([], $manager->exportFor('u-ann'));
        self::assertCount(2, $manager->exportFor('u-bob'), 'Only that subject');

        $this->service($kernel, LegalPageVersions::class)->publish('terms', true, 'editor', ['es' => 'Cambios.']);
        self::assertNull($manager->pendingFor('u-bob', 'de')[0]->changeSummary ?? null, 'Content, request and default locale only');
        self::assertSame('Cambios.', $manager->pendingFor('u-bob', 'es')[0]->changeSummary ?? null);
    }

    public function testGateHelpers(): void
    {
        $kernel = $this->acceptanceKernel(['routing' => ['default_locale_style' => 'prefixed']]);
        $gate   = $this->service($kernel, AcceptanceGateSubscriber::class);

        self::assertSame('/en/legal/accept', $gate->interstitialUrl('en'));
        self::assertSame('/legal/accept', $gate->interstitialUrl('xx'));
    }

    public function testConfigurationGuards(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('subject_resolver');
        $this->versioningKernel(['acceptance' => ['enabled' => true]]);
    }
}
