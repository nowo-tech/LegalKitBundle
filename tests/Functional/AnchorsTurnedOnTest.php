<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Functional;

use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceManager;
use Nowo\LegalKitBundle\Command\VerifyAcceptanceHashCommand;
use Nowo\LegalKitBundle\Document\LegalDocumentResolver;
use Nowo\LegalKitBundle\Document\ResolvedLegalDocument;
use Nowo\LegalKitBundle\Tests\Support\TestKernel;
use Nowo\LegalKitBundle\Tests\Support\VersioningTestCase;
use Nowo\LegalKitBundle\Versioning\LegalPageVersions;
use Nowo\LegalKitBundle\Versioning\LegalSeedHasher;
use Nowo\LegalKitBundle\Versioning\SeedSnapshotStore;
use Symfony\Component\Console\Command\Command;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function hash;
use function json_decode;
use function json_encode;
use function str_repeat;
use function unlink;

/**
 * Turning `sanitizer.heading_anchors` on for text that already carries the classes (Appendix HB, HB-M1): snapshots and
 * bases taken before stay usable, nobody is asked to accept unchanged text again, and an old acceptance hash can still
 * be checked. Each kernel is one deploy; the snapshot directory survives between them.
 */
final class AnchorsTurnedOnTest extends VersioningTestCase
{
    private const array ON = ['sanitizer' => ['heading_anchors' => ['retention']]];

    /**
     * @param array<string, mixed> $config
     */
    private function kernel(string $revision, string $template = 'privacy', array $config = []): TestKernel
    {
        return $this->acceptanceKernel(['seed' => ['revision' => $revision], 'pages' => ['privacy' => ['seed_template' => '@Anchored/' . $template . '.html.twig']]] + $config, []);
    }

    protected function acceptanceKernel(array $config = [], array $extraBundles = ['app_routes']): TestKernel
    {
        return $this->versioningKernel(['acceptance' => ['enabled' => true, 'subject_resolver' => 'test.legal_kit.subject_resolver']] + $config, $extraBundles, ['twig' => ['paths' => [dirname(__DIR__) . '/Support/templates/anchored' => 'Anchored']]]);
    }

    private function resolved(TestKernel $kernel): ResolvedLegalDocument
    {
        $document = $this->service($kernel, LegalDocumentResolver::class)->resolve('privacy', 'en');
        self::assertInstanceOf(ResolvedLegalDocument::class, $document);

        return $document;
    }

    public function testUnchangedTextIsNotANewVersionAfterTurningAnchorsOn(): void
    {
        $this->tester($this->kernel('r0'), 'nowo:legal-kit:snapshot-seed')->execute([]);

        $kernel = $this->kernel('r0', config: self::ON);
        self::assertStringContainsString('id="retention"', $this->resolved($kernel)->body);
        $tester = $this->tester($kernel, 'nowo:legal-kit:upgrade-seed');

        self::assertSame(Command::SUCCESS, $tester->execute(['--from' => 'r0', '--requires-acceptance' => true]));
        self::assertStringNotContainsString('Version published: privacy', $tester->getDisplay());
        self::assertSame(1, $this->service($kernel, LegalPageVersions::class)->requirements()['privacy'][0] ?? 1, 'Still version 1');
    }

    public function testChangedTextIsStillANewVersion(): void
    {
        $this->tester($this->kernel('r0'), 'nowo:legal-kit:snapshot-seed')->execute([]);

        $kernel = $this->kernel('r1', 'privacy_new', self::ON);
        $tester = $this->tester($kernel, 'nowo:legal-kit:upgrade-seed');

        self::assertSame(Command::SUCCESS, $tester->execute(['--from' => 'r0', '--requires-acceptance' => true]));
        self::assertStringContainsString('Version published: privacy v2 (acceptance required)', $tester->getDisplay());
    }

    public function testTamperedSnapshotIsStillUnknown(): void
    {
        $this->tester($this->kernel('r0'), 'nowo:legal-kit:snapshot-seed')->execute([]);
        $file = self::snapshotsDir() . '/r0/privacy.en.html';
        file_put_contents($file, (string) file_get_contents($file) . '<p>Added later</p>');

        self::assertNull($this->service($this->kernel('r0', config: self::ON), SeedSnapshotStore::class)->hash('r0', 'privacy', 'en'));
    }

    public function testSnapshotDeclaringTheIdsStaysKnownWhenTakenWithTheOptionOn(): void
    {
        $kernel = $this->kernel('r0', config: self::ON);
        $this->tester($kernel, 'nowo:legal-kit:snapshot-seed')->execute([]);
        $manifest = (array) json_decode((string) file_get_contents(self::snapshotsDir() . '/r0/manifest.json'), true);
        self::assertSame(json_encode($manifest['pages']['privacy']['en']['hash'] ?? null), json_encode($this->service($kernel, SeedSnapshotStore::class)->hash('r0', 'privacy', 'en')));
    }

    public function testUneditedRowWithABaseFromBeforeTheSwitchTakesTheNewSeed(): void
    {
        $old    = '<h1>Privacy policy</h1><h2 class="legal-anchor--retention">Retention</h2><p>Unchanged text.</p>';
        $before = $this->service($this->kernel('r0'), LegalSeedHasher::class)->hash('Privacy policy', $old);

        $kernel = $this->kernel('r1', 'privacy_new', self::ON);
        $this->storeWithBase($kernel, 'privacy', 'en', 'Privacy policy', $old, 'r0', $before);
        $this->tester($kernel, 'nowo:legal-kit:upgrade-seed')->execute(['--from' => 'r0']);

        self::assertStringContainsString('New text', $this->found($kernel, 'privacy', 'en')->getBody());
    }

    public function testEditedRowIsStillLeftAlone(): void
    {
        $before = $this->service($this->kernel('r0'), LegalSeedHasher::class)->hash('Privacy policy', '<p>Original</p>');

        $kernel = $this->kernel('r1', 'privacy_new', self::ON);
        $this->storeWithBase($kernel, 'privacy', 'en', 'Privacy policy', '<h2 class="legal-anchor--retention">Mine</h2>', 'r0', $before);
        $this->tester($kernel, 'nowo:legal-kit:upgrade-seed')->execute(['--from' => 'r0']);

        self::assertStringContainsString('Mine', $this->found($kernel, 'privacy', 'en')->getBody());
    }

    /**
     * Appendix HB, HB-L3: an earlier text holds `<a title="<h2" ...>`, a later one adds a clause inside the same shape.
     * Stripping must not make them the same text, with the option on or off.
     */
    public function testPlantedAttributeValueIsNotAnAnchorForTheCommandTheSnapshotOrARow(): void
    {
        $earlier = '<p>Intro</p><a title="<h2">x</a>';
        $later   = '<p>Intro</p><a title="<h2 id=">NEW CLAUSE: we may sell your data.</a><a title="">x</a>';

        foreach ([[], self::ON] as $config) {
            $kernel = $this->kernel('r0', config: $config);
            $this->store($kernel, 'privacy', 'en', 'Privacy', $earlier);
            $recorded = LegalAcceptanceManager::contentHash($this->resolved($kernel));
            $document = $this->found($kernel, 'privacy', 'en');
            $document->setBody($later);
            $this->entityManager($kernel)->flush();
            self::assertSame(Command::FAILURE, $this->tester($kernel, VerifyAcceptanceHashCommand::NAME)->execute(['slug' => 'privacy', 'locale' => 'en', 'hash' => $recorded]), 'The command must not call the later text a match');

            $store = $this->service($kernel, SeedSnapshotStore::class);
            $store->write('planted', ['privacy' => ['en' => ['title' => 'Privacy', 'body' => $earlier]]], true);
            file_put_contents(self::snapshotsDir() . '/planted/privacy.en.html', $later);
            self::assertNull($store->entry('planted', 'privacy', 'en'), 'A replaced snapshot file is unknown');

            $edited = $this->kernel('r1', 'privacy_new', $config);
            $base   = $this->service($edited, LegalSeedHasher::class)->hash('Privacy', $earlier);
            $this->storeWithBase($edited, 'privacy', 'en', 'Privacy', $later, 'r0', $base);
            $this->tester($edited, 'nowo:legal-kit:upgrade-seed')->execute(['--from' => 'r0']);
            self::assertStringContainsString('NEW CLAUSE', $this->found($edited, 'privacy', 'en')->getBody(), 'An edited row is not overwritten');
        }
    }

    /**
     * What UPGRADING tells the reader to check. Appendix HB, HB-L5.
     */
    public function testTheDryRunOfTheUpgradeGuideCanFail(): void
    {
        $this->tester($this->kernel('r0'), 'nowo:legal-kit:snapshot-seed')->execute([]);
        $kernel = $this->kernel('r0', config: self::ON);
        $tester = $this->tester($kernel, 'nowo:legal-kit:upgrade-seed');

        self::assertSame(Command::SUCCESS, $tester->execute(['--from' => 'r0', '--requires-acceptance' => true, '--dry-run' => true]));
        self::assertStringContainsString('seed_unknown: 0', $tester->getDisplay());
        self::assertStringNotContainsString('Version published', $tester->getDisplay());

        unlink(self::snapshotsDir() . '/r0/privacy.en.html');
        self::assertSame(Command::SUCCESS, $tester->execute(['--from' => 'r0', '--requires-acceptance' => true, '--dry-run' => true]));
        self::assertStringContainsString('seed_unknown: 1', $tester->getDisplay());
        self::assertStringContainsString('Version published: privacy', $tester->getDisplay(), 'The check shows the problem');

        self::assertSame(Command::SUCCESS, $tester->execute(['--from' => 'r0', '--dry-run' => true]));
        self::assertStringNotContainsString('Version published', $tester->getDisplay(), 'Without the flag the dry run proves nothing');
    }

    public function testVerifyCommand(): void
    {
        $recorded = LegalAcceptanceManager::contentHash($this->resolved($this->kernel('r0')));
        $kernel   = $this->kernel('r0', config: self::ON);
        $current  = LegalAcceptanceManager::contentHash($this->resolved($kernel));
        self::assertNotSame($recorded, $current, 'The switch changes the hash of this text');
        $tester = $this->tester($kernel, VerifyAcceptanceHashCommand::NAME);

        self::assertSame(Command::SUCCESS, $tester->execute(['slug' => 'privacy', 'locale' => 'en', 'hash' => $current]));
        self::assertStringContainsString('matches the current text.', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['slug' => 'privacy', 'locale' => 'en', 'hash' => $recorded]));
        self::assertStringContainsString('without its anchor ids', $tester->getDisplay());

        self::assertSame(Command::FAILURE, $tester->execute(['slug' => 'privacy', 'locale' => 'en', 'hash' => hash('sha256', 'other')]));
        self::assertStringContainsString('does not match', $tester->getDisplay());

        self::assertSame(Command::INVALID, $tester->execute(['slug' => 'privacy', 'locale' => 'en', 'hash' => str_repeat('z', 64)]));
        self::assertSame(Command::INVALID, $tester->execute(['slug' => 'nope', 'locale' => 'en', 'hash' => $current]));
    }
}
