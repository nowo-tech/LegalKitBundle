<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Functional;

use Nowo\LegalKitBundle\Tests\Support\VersioningTestCase;
use Nowo\LegalKitBundle\Versioning\LegalPageVersions;
use Nowo\LegalKitBundle\Versioning\LegalSeedHasher;
use Nowo\LegalKitBundle\Versioning\LegalSeedTracker;
use Nowo\LegalKitBundle\Versioning\SeedSnapshotStore;

/**
 * Versioning admin: outdated badge, compare views (escaping), keep my text / use the new text, the minor / new
 * version choice, its rules (summary, publisher check, optimistic guard) and the version aside.
 */
final class VersioningAdminTest extends VersioningTestCase
{
    private const string PAYLOAD = '<p>Ours &lt;script&gt;alert(1)&lt;/script&gt; <a href="javascript:alert(2)">x</a> "quoted" &amp; done</p>';

    public function testIndexBadgeAndCompareViews(): void
    {
        $kernel = $this->versioningKernel(['locales' => ['en', 'es']]);
        $this->storeWithBase($kernel, 'privacy', 'es', 'Mi privacidad', self::PAYLOAD, 'old', null);
        $this->storeWithBase($kernel, 'terms', 'en', 'Terms', '<p>Current base</p>', '2026-10-18', null);
        $client = $this->adminClient($kernel);

        $client->request('GET', '/admin/legal');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-testid="nowo-legal-kit-outdated-privacy-es"', $html);
        self::assertStringContainsString('href="/admin/legal/privacy/es/compare"', $html);
        self::assertStringNotContainsString('nowo-legal-kit-outdated-terms-en', $html, 'Based on the current revision');
        self::assertStringContainsString('Built-in text changed', $html);

        $client->request('GET', '/admin/legal/privacy/es/compare');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-testid="nowo-legal-kit-compare-changes"', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html, 'Decoded text is escaped again');
        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringNotContainsString('javascript:alert(2)', $html);
        self::assertStringContainsString('&quot;quoted&quot; &amp; done', $html);
        self::assertStringContainsString('<del>', $html);
        self::assertStringContainsString('<ins>', $html);
        self::assertStringNotContainsString('view=builtin', $html, 'No snapshot of the base: no built-in changes tab');
        self::assertStringContainsString('href="/admin/legal/privacy/es?from_seed=1"', $html);
        self::assertStringContainsString('name="nowo_legal_kit_rebase', $html);

        $client->request('GET', '/admin/legal/privacy/es/compare?view=side');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-testid="nowo-legal-kit-compare-side"', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringContainsString('Política de privacidad', $html);

        $client->request('GET', '/admin/legal/privacy/es/compare?view=builtin');
        self::assertStringContainsString('data-testid="nowo-legal-kit-compare-changes"', (string) $client->getResponse()->getContent(), 'Unavailable view falls back');

        $this->service($kernel, SeedSnapshotStore::class)->write('old', ['privacy' => ['es' => ['title' => 'Privacidad antigua', 'body' => '<p>Texto antiguo</p>']]]);
        $client->request('GET', '/admin/legal/privacy/es/compare?view=builtin');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-testid="nowo-legal-kit-compare-builtin"', $html);
        self::assertStringContainsString('antiguo', $html);

        $client->request('GET', '/admin/legal/notice/es/compare');
        self::assertSame('/admin/legal/notice/es', $client->getResponse()->headers->get('Location'), 'Nothing stored: back to the editor');
        $client->request('GET', '/admin/legal/imprint/es/compare');
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testDiffLimitsAndEqualTexts(): void
    {
        $kernel  = $this->versioningKernel(['locales' => ['en']]);
        $tracker = $this->service($kernel, LegalSeedTracker::class);
        $seed    = $tracker->currentSeed('notice', 'en');
        self::assertNotNull($seed);
        $this->storeWithBase($kernel, 'notice', 'en', $seed->title, $seed->body, 'old', null);
        $lines = '';
        for ($i = 0; $i < 700; ++$i) {
            $lines .= '<p>Line ' . $i . '</p>';
        }
        $this->storeWithBase($kernel, 'terms', 'en', 'Terms', $lines, 'old', null);
        $client = $this->adminClient($kernel);

        $client->request('GET', '/admin/legal/notice/en/compare');
        self::assertStringContainsString('No differences.', (string) $client->getResponse()->getContent());
        $client->request('GET', '/admin/legal/terms/en/compare');
        self::assertStringContainsString('data-testid="nowo-legal-kit-diff-too-large"', (string) $client->getResponse()->getContent());
    }

    public function testKeepMyTextAndUseTheNewText(): void
    {
        $kernel = $this->versioningKernel(['locales' => ['en'], 'seed' => ['revision' => 'new']]);
        $this->storeWithBase($kernel, 'privacy', 'en', 'Mine', '<p>Mine</p>', 'old', null);
        $client  = $this->adminClient($kernel);
        $crawler = $client->request('GET', '/admin/legal/privacy/en/compare');

        $client->request('POST', '/admin/legal/privacy/en/rebase', ['nowo_legal_kit_rebase' => ['_token' => 'forged']]);
        self::assertSame('/admin/legal/privacy/en', $client->getResponse()->headers->get('Location'));
        self::assertSame('old', $this->found($kernel, 'privacy', 'en')->getSeedRevision(), 'Invalid CSRF: unchanged');

        $client->submit($crawler->filter('form[name="nowo_legal_kit_rebase"]')->form());
        self::assertSame('/admin/legal/privacy/en', $client->getResponse()->headers->get('Location'));
        $row = $this->found($kernel, 'privacy', 'en');
        self::assertSame('new', $row->getSeedRevision());
        self::assertSame('<p>Mine</p>', $row->getBody(), 'Keep my text keeps the text');
        self::assertSame($this->service($kernel, LegalSeedTracker::class)->currentSeedHash('privacy', 'en'), $row->getSeedHash());

        $this->storeWithBase($kernel, 'notice', 'en', 'Mine', '<p>Mine</p>', 'old', null);
        $crawler = $client->request('GET', '/admin/legal/notice/en?from_seed=1');
        self::assertStringContainsString('Legal notice', (string) $client->getResponse()->getContent(), 'Prefilled with the new built-in text');
        $client->submit($crawler->filter('form[name="nowo_legal_kit_document"]')->form());
        $row = $this->found($kernel, 'notice', 'en');
        self::assertSame('new', $row->getSeedRevision());
        self::assertNotSame('<p>Mine</p>', $row->getBody());
        self::assertSame($this->service($kernel, LegalSeedHasher::class)->hash((string) $row->getTitle(), (string) $row->getBody()), $row->getSeedHash(), 'Unedited new text');
    }

    public function testPublicationChoice(): void
    {
        $kernel   = $this->versioningKernel(['locales' => ['en', 'es']]);
        $versions = $this->service($kernel, LegalPageVersions::class);
        $client   = $this->adminClient($kernel);

        $crawler = $client->request('GET', '/admin/legal/terms/en');
        $html    = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('name="nowo_legal_kit_document[publication]"', $html);
        self::assertStringNotContainsString('checked="checked" value="minor"', $html);
        self::assertStringContainsString('Users must accept this version', $html);
        self::assertStringContainsString('Current version: 1', $html);

        $form = $crawler->filter('form[name="nowo_legal_kit_document"]')->form();
        $client->submit($form);
        self::assertSame(422, $client->getResponse()->getStatusCode(), 'The choice is required');
        self::assertSame([], $versions->requirements());

        self::choose($form, 'nowo_legal_kit_document[publication]', 'new_version');
        $client->submit($form);
        self::assertSame(422, $client->getResponse()->getStatusCode(), 'A version users accept needs a summary');
        self::assertStringContainsString('Add a change summary', (string) $client->getResponse()->getContent());

        $form['nowo_legal_kit_document[change_summary]'] = "We changed\u{0007} the rules.\nSee section 3.";
        $client->submit($form);
        self::assertSame('/admin/legal/terms/en', $client->getResponse()->headers->get('Location'));
        self::assertSame(['terms' => [2, 2]], $versions->requirements());
        self::assertSame(['en' => "We changed  the rules.\nSee section 3."], $versions->latest('terms')?->getChangeSummary());
        $crawler = $client->followRedirect();
        $html    = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Saved and published as version 2.', $html);
        self::assertStringContainsString('Current version: 2', $html);
        self::assertStringContainsString('not updated since version 2', $html, 'es was not updated for v2');

        // Minor correction: same version, the summary of this locale can be edited.
        $form = $crawler->filter('form[name="nowo_legal_kit_document"]')->form();
        self::choose($form, 'nowo_legal_kit_document[publication]', 'minor');
        $form['nowo_legal_kit_document[change_summary]'] = 'Clearer wording.';
        $client->submit($form);
        self::assertSame(['terms' => [2, 2]], $versions->requirements());
        self::assertSame(['en' => 'Clearer wording.'], $versions->latest('terms')?->getChangeSummary());

        // New version without acceptance.
        $crawler = $client->request('GET', '/admin/legal/terms/es');
        $form    = $crawler->filter('form[name="nowo_legal_kit_document"]')->form();
        self::choose($form, 'nowo_legal_kit_document[publication]', 'new_version');
        self::tick($form, 'nowo_legal_kit_document[requires_acceptance]', false);
        $client->submit($form);
        self::assertSame(['terms' => [3, 2]], $versions->requirements());

        // A page users do not accept: minor is preselected.
        $crawler = $client->request('GET', '/admin/legal/notice/en');
        self::assertStringNotContainsString('requires_acceptance', (string) $client->getResponse()->getContent());
        $client->submit($crawler->filter('form[name="nowo_legal_kit_document"]')->form());
        self::assertSame('/admin/legal/notice/en', $client->getResponse()->headers->get('Location'));
        self::assertArrayNotHasKey('notice', $versions->requirements());
    }

    public function testOptimisticGuard(): void
    {
        $kernel   = $this->versioningKernel(['locales' => ['en']]);
        $versions = $this->service($kernel, LegalPageVersions::class);
        $client   = $this->adminClient($kernel);
        $crawler  = $client->request('GET', '/admin/legal/notice/en');
        $form     = $crawler->filter('form[name="nowo_legal_kit_document"]')->form();

        $versions->publish('notice', false, 'editor');
        $client->submit($form);
        self::assertSame('/admin/legal/notice/en', $client->getResponse()->headers->get('Location'));
        self::assertNull($this->find($kernel, 'notice', 'en'), 'Nothing saved');
        $client->followRedirect();
        self::assertStringContainsString('This page changed while you were editing it.', (string) $client->getResponse()->getContent());
    }

    public function testPublisherCheck(): void
    {
        $kernel = $this->versioningKernel(['locales' => ['en'], 'versioning' => ['publisher_checker' => 'test.legal_kit.deny_publish']]);
        $client = $this->adminClient($kernel);

        $crawler = $client->request('GET', '/admin/legal/terms/en');
        self::assertStringNotContainsString('[publication]', (string) $client->getResponse()->getContent());
        $client->submit($crawler->filter('form[name="nowo_legal_kit_document"]')->form());
        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertNull($this->find($kernel, 'terms', 'en'));

        $crawler = $client->request('GET', '/admin/legal/notice/en');
        $client->submit($crawler->filter('form[name="nowo_legal_kit_document"]')->form());
        self::assertNotNull($this->find($kernel, 'notice', 'en'), 'Pages users do not accept are saved as minor corrections');
    }

    public function testCompareNeedsAdminAccess(): void
    {
        $client = $this->adminClient($this->versioningKernel(), 'editor');
        $client->request('GET', '/admin/legal/privacy/en/compare');

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }
}
