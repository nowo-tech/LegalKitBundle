<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Functional;

use Dom\HTMLDocument;
use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceManager;
use Nowo\LegalKitBundle\Command\DoctorCommand;
use Nowo\LegalKitBundle\Command\VerifyAcceptanceHashCommand;
use Nowo\LegalKitBundle\Document\LegalDocumentResolver;
use Nowo\LegalKitBundle\Document\ResolvedLegalDocument;
use Nowo\LegalKitBundle\Repository\LegalDocumentRepository;
use Nowo\LegalKitBundle\Sanitizer\LegalHtmlSanitizerInterface;
use Nowo\LegalKitBundle\Seed\LegalSeedRegistry;
use Nowo\LegalKitBundle\Tests\Support\TestKernel;
use Nowo\LegalKitBundle\Tests\Support\VersioningTestCase;
use Nowo\LegalKitBundle\Token\LegalTokenRenderer;
use Nowo\LegalKitBundle\Versioning\LegalSeedTracker;
use Nowo\LegalKitBundle\Versioning\SeedSnapshotStore;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\Console\Command\Command;

use function class_exists;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function json_decode;
use function json_encode;
use function str_repeat;

/**
 * Text with nothing left after the sanitiser (over the nesting limit, or empty): never a blank page, never accepted,
 * never "the same text", always reported; and users keep their access to the app. Appendix HB, HB-M2.
 */
final class RefusedTextTest extends VersioningTestCase
{
    private const string DEEP = '<span>';

    private static function overLimit(): string
    {
        return str_repeat(self::DEEP, 600) . 'Terms text';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function kernel(array $config = []): TestKernel
    {
        return $this->versioningKernel([
            'acceptance' => ['enabled' => true, 'enforce' => true, 'subject_resolver' => 'test.legal_kit.subject_resolver', 'exempt_routes' => ['app_skip']],
        ] + $config, ['app_routes'], ['twig' => ['paths' => [dirname(__DIR__) . '/Support/templates/anchored' => 'Anchored']]]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function seedKernel(string $revision, string $template, array $config = []): TestKernel
    {
        return $this->kernel(['seed' => ['revision' => $revision], 'pages' => ['privacy' => ['seed_template' => '@Anchored/' . $template . '.html.twig']]] + $config);
    }

    public function testStoredTextOverTheLimitIs404NotBlank(): void
    {
        $kernel = $this->kernel();
        $this->store($kernel, 'terms', 'en', 'Terms', self::overLimit());
        $client = $this->adminClient($kernel);
        $client->request('GET', '/legal/terms');

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testNothingOfARefusedStoredTextFallsBackToTheSeed(): void
    {
        $kernel = $this->kernel();
        $this->store($kernel, 'privacy', 'en', 'Privacy', self::overLimit());

        self::assertNull($this->service($kernel, LegalDocumentResolver::class)->resolve('privacy', 'en'), 'Another text must not stand in for what the operator published');
    }

    public function testBelowTheLimitIsServed(): void
    {
        // 500 levels on the HTML5 parser; libxml (PHP 8.3) stops at 256 and drops what is deeper.
        $depth  = class_exists(HTMLDocument::class) ? 500 : 200;
        $kernel = $this->kernel();
        $this->store($kernel, 'terms', 'en', 'Terms', str_repeat(self::DEEP, $depth) . 'Terms text');
        $client = $this->adminClient($kernel);
        $client->request('GET', '/legal/terms');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Terms text', (string) $client->getResponse()->getContent());
    }

    public function testAnErrorIsLogged(): void
    {
        $kernel = $this->kernel();
        $this->store($kernel, 'terms', 'en', 'Terms', self::overLimit());
        $logger = new class extends AbstractLogger {
            /** @var list<array{mixed, string, array<array-key, mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };
        $resolver = new LegalDocumentResolver(
            $this->service($kernel, LegalDocumentRepository::class),
            $this->service($kernel, LegalSeedRegistry::class),
            $this->service($kernel, LegalHtmlSanitizerInterface::class),
            $this->service($kernel, LegalTokenRenderer::class),
            null,
            $logger,
        );

        self::assertNull($resolver->resolve('terms', 'en'));
        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0][0]);
        self::assertSame(['slug' => 'terms', 'locale' => 'en', 'source' => 'stored'], [
            'slug'   => $logger->records[0][2]['slug'] ?? null,
            'locale' => $logger->records[0][2]['locale'] ?? null,
            'source' => $logger->records[0][2]['source'] ?? null,
        ]);
    }

    public function testNobodyIsAskedToAcceptItAndNobodyIsLockedOut(): void
    {
        $kernel = $this->kernel(['pages' => ['privacy' => ['acceptance' => 'none']]]);
        $this->store($kernel, 'terms', 'en', 'Terms', self::overLimit());
        $client = $this->adminClient($kernel);
        $client->setServerParameter('HTTP_ACCEPT', 'text/html,application/xhtml+xml');
        $client->request('GET', '/app/skip');

        $client->request('GET', '/app');
        self::assertSame(200, $client->getResponse()->getStatusCode(), 'The gate lets the user through');
        self::assertSame([], $this->service($kernel, LegalAcceptanceManager::class)->pendingFor('admin'));

        $client->request('GET', '/legal/accept');
        self::assertStringNotContainsString('nowo-legal-kit-acceptance-terms', (string) $client->getResponse()->getContent());
    }

    public function testARefusedPageIsNotCountedAsAcceptedOnceFixed(): void
    {
        $kernel  = $this->kernel();
        $manager = $this->service($kernel, LegalAcceptanceManager::class);
        $this->store($kernel, 'terms', 'en', 'Terms', self::overLimit());
        self::assertNotContains('terms', array_map(static fn ($p) => $p->page->slug, $manager->pendingFor('admin')));

        $document = $this->found($kernel, 'terms', 'en');
        $document->setBody('<p>Fixed</p>');
        $this->entityManager($kernel)->flush();

        self::assertContains('terms', array_map(static fn ($p) => $p->page->slug, $manager->pendingFor('admin')));
    }

    public function testOtherPagesCanStillBeAccepted(): void
    {
        $kernel = $this->kernel();
        $this->store($kernel, 'privacy', 'en', 'Privacy', self::overLimit());

        $slugs = array_map(static fn ($p) => $p->page->slug, $this->service($kernel, LegalAcceptanceManager::class)->pendingFor('admin'));
        self::assertSame(['terms'], $slugs);
    }

    public function testDoctorReportsStoredTextAndSeed(): void
    {
        $kernel = $this->seedKernel('r0', 'deep_one');
        $this->store($kernel, 'terms', 'en', 'Terms', self::overLimit());
        $tester = $this->tester($kernel, DoctorCommand::NAME);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('The stored text of "terms" (en)', $display);
        self::assertStringContainsString('The seed text of "privacy" (en)', $display);
    }

    public function testDoctorIsQuietAboutGoodTexts(): void
    {
        $tester = $this->tester($this->kernel(), DoctorCommand::NAME);
        $tester->execute([]);

        self::assertStringNotContainsString('after the sanitiser', $tester->getDisplay());
    }

    public function testASeedOverTheLimitHasNoCurrentSeedAndIs404(): void
    {
        $kernel = $this->seedKernel('r0', 'deep_one');

        self::assertNull($this->service($kernel, LegalSeedTracker::class)->currentSeed('privacy', 'en'));
        $client = $this->adminClient($kernel);
        $client->request('GET', '/legal/privacy');
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testTwoDifferentSeedsOverTheLimitAreNotTheSameText(): void
    {
        $writer = $this->seedKernel('r0', 'deep_one');
        $this->service($writer, SeedSnapshotStore::class)->write('r0', ['privacy' => ['en' => ['title' => 'Privacy', 'body' => str_repeat('<b>', 600) . 'Version ONE']]]);
        self::assertNull($this->service($writer, SeedSnapshotStore::class)->entry('r0', 'privacy', 'en'), 'The snapshot entry is unknown');

        $kernel = $this->seedKernel('r1', 'deep_two');
        $tester = $this->tester($kernel, 'nowo:legal-kit:upgrade-seed');
        self::assertSame(Command::SUCCESS, $tester->execute(['--from' => 'r0', '--requires-acceptance' => true]));
        $display = $tester->getDisplay();

        self::assertStringContainsString('seed_same: 0', $display);
        self::assertStringNotContainsString('Version published: privacy', $display, 'Nothing to publish while there is no text');
    }

    public function testSnapshotSkipsARefusedSeed(): void
    {
        $kernel = $this->seedKernel('r0', 'deep_one');
        $this->tester($kernel, 'nowo:legal-kit:snapshot-seed')->execute([]);

        self::assertNull($this->service($kernel, SeedSnapshotStore::class)->entry('r0', 'privacy', 'en'));
        self::assertNotNull($this->service($kernel, SeedSnapshotStore::class)->entry('r0', 'terms', 'en'));
    }

    public function testComparePageAndEditorStillOpen(): void
    {
        $kernel = $this->kernel();
        $this->store($kernel, 'terms', 'en', 'Terms', self::overLimit());
        $client = $this->adminClient($kernel);
        $client->request('GET', '/admin/legal/terms/en');
        self::assertSame(200, $client->getResponse()->getStatusCode(), 'The operator can fix it');
        foreach (['', '?view=side'] as $query) {
            $client->request('GET', '/admin/legal/terms/en/compare' . $query);
            self::assertSame(200, $client->getResponse()->getStatusCode(), $query);
        }

        $refused = $this->seedKernel('r0', 'deep_one');
        $this->store($refused, 'privacy', 'en', 'Privacy', '<p>Mine</p>');
        $admin = $this->adminClient($refused);
        $admin->request('GET', '/admin/legal/privacy/en/compare');
        self::assertSame(302, $admin->getResponse()->getStatusCode(), 'No built-in text to compare with: back to the editor');
    }

    public function testExportKeepsTheStoredTextSoItCanBeFixed(): void
    {
        $kernel = $this->kernel();
        $this->store($kernel, 'terms', 'en', 'Terms', self::overLimit());
        $tester = $this->tester($kernel, 'nowo:legal-kit:export');

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Terms text', $tester->getDisplay());
    }

    public function testVerifyCommandSaysThereIsNoText(): void
    {
        $kernel = $this->kernel();
        $this->store($kernel, 'terms', 'en', 'Terms', self::overLimit());
        $tester = $this->tester($kernel, VerifyAcceptanceHashCommand::NAME);

        self::assertSame(Command::INVALID, $tester->execute(['slug' => 'terms', 'locale' => 'en', 'hash' => str_repeat('a', 64)]));
    }

    /**
     * A row emptied on purpose has no text of its own, so the seed applies, as it always did. No error.
     */
    public function testDeliberatelyEmptyStoredRowFallsBackToTheSeed(): void
    {
        $kernel = $this->kernel();
        $this->store($kernel, 'terms', 'en', 'Terms', '<p>&nbsp;</p>');

        $document = $this->service($kernel, LegalDocumentResolver::class)->resolve('terms', 'en');
        self::assertInstanceOf(ResolvedLegalDocument::class, $document);
        self::assertSame(ResolvedLegalDocument::SOURCE_SEED, $document->source);
        self::assertSame([], $this->service($kernel, LegalDocumentResolver::class)->refusedSources('terms', 'en'));
    }

    /**
     * A row whose only "text" is removed by the sanitiser was a blank 200: it is refused like the others.
     */
    public function testStoredTextThatTheSanitiserEmptiesIsRefused(): void
    {
        $kernel = $this->kernel();
        $this->store($kernel, 'terms', 'en', 'Terms', '<script>alert(1)</script>');

        self::assertNull($this->service($kernel, LegalDocumentResolver::class)->resolve('terms', 'en'));
        self::assertSame(['stored'], $this->service($kernel, LegalDocumentResolver::class)->refusedSources('terms', 'en'));
    }

    /**
     * An empty seed cannot fall back to anything: 404 and reported, instead of a blank page users could accept.
     */
    public function testEmptySeedIsRefused(): void
    {
        $kernel = $this->seedKernel('r0', 'empty');

        self::assertNull($this->service($kernel, LegalDocumentResolver::class)->resolve('privacy', 'en'));
        self::assertSame(['seed'], $this->service($kernel, LegalDocumentResolver::class)->refusedSources('privacy', 'en'));
    }

    public function testSnapshotEntryWithoutADeclaredHashIsUnknown(): void
    {
        $kernel = $this->kernel(['seed' => ['revision' => 'r0']]);
        $this->tester($kernel, 'nowo:legal-kit:snapshot-seed')->execute([]);
        $store = $this->service($kernel, SeedSnapshotStore::class);
        self::assertNotNull($store->entry('r0', 'privacy', 'en'));

        $file     = self::snapshotsDir() . '/r0/manifest.json';
        $manifest = (array) json_decode((string) file_get_contents($file), true);
        unset($manifest['pages']['privacy']['en']['hash']);
        file_put_contents($file, (string) json_encode($manifest));

        self::assertNull($this->service($this->kernel(['seed' => ['revision' => 'r0']]), SeedSnapshotStore::class)->entry('r0', 'privacy', 'en'));
    }
}
