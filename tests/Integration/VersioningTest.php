<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Integration;

use Doctrine\ORM\Events;
use Nowo\LegalKitBundle\Catalog\LegalPageCatalogInterface;
use Nowo\LegalKitBundle\Command\UpgradeSeedCommand;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use Nowo\LegalKitBundle\Event\LegalVersionPublishedEvent;
use Nowo\LegalKitBundle\Locale\LegalLocales;
use Nowo\LegalKitBundle\Portability\LegalDocumentPortability;
use Nowo\LegalKitBundle\Sanitizer\LegalHtmlSanitizerInterface;
use Nowo\LegalKitBundle\Seed\LegalSeedRegistry;
use Nowo\LegalKitBundle\Tests\Support\VersioningTestCase;
use Nowo\LegalKitBundle\Versioning\Entity\LegalPageVersion;
use Nowo\LegalKitBundle\Versioning\LegalPageVersions;
use Nowo\LegalKitBundle\Versioning\LegalSeedHasher;
use Nowo\LegalKitBundle\Versioning\LegalSeedTracker;
use Nowo\LegalKitBundle\Versioning\LegalSeedUpgrader;
use Nowo\LegalKitBundle\Versioning\SeedSnapshotStore;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Twig\Environment;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function rmdir;
use function symlink;
use function sys_get_temp_dir;
use function unlink;

/**
 * Versioning: opt-in mapping, seed tracking, snapshot-seed, upgrade-seed (every result, dry run, idempotence,
 * versions), link-canonical hashes across default locales, Twig functions, doctor.
 */
final class VersioningTest extends VersioningTestCase
{
    public function testOffByDefault(): void
    {
        $kernel    = $this->bootKernel();
        $container = $this->testContainer($kernel);

        self::assertFalse($container->has(LegalPageVersions::class));
        self::assertFalse($this->entityManager($kernel)->getClassMetadata(LegalDocument::class)->hasField('seedRevision'));
        $twig = $container->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        self::assertSame('0|0', $twig->createTemplate('{{ nowo_legal_kit_outdated_count() }}|{{ nowo_legal_kit_outdated()|length }}')->render());
    }

    public function testMappingAndServices(): void
    {
        $kernel   = $this->versioningKernel(['doctrine' => ['table_prefix' => 'app_']]);
        $manager  = $this->entityManager($kernel);
        $metadata = $manager->getClassMetadata(LegalDocument::class);

        self::assertTrue($metadata->hasField('seedRevision'));
        self::assertSame('seed_revision', $metadata->getColumnName('seedRevision'));
        self::assertSame('seed_hash', $metadata->getColumnName('seedHash'));
        self::assertSame('app_legal_page_version', $manager->getClassMetadata(LegalPageVersion::class)->getTableName());
        self::assertSame('2026-10-18', $this->service($kernel, LegalSeedTracker::class)->revision(), 'seed.revision defaults to seed.date');
    }

    public function testSeedCommandAndImportTrackTheBase(): void
    {
        $kernel  = $this->versioningKernel(['locales' => ['en'], 'seed' => ['materialize' => true, 'revision' => 'r2']]);
        $tracker = $this->service($kernel, LegalSeedTracker::class);
        self::assertInstanceOf(LegalSeedTracker::class, $tracker);

        self::assertSame(Command::SUCCESS, $this->tester($kernel, 'nowo:legal-kit:seed')->execute([]));
        $terms = $this->found($kernel, 'terms', 'en');
        self::assertSame('r2', $terms->getSeedRevision());
        self::assertSame($tracker->currentSeedHash('terms', 'en'), $terms->getSeedHash());

        $portability = $this->service($kernel, LegalDocumentPortability::class);
        self::assertInstanceOf(LegalDocumentPortability::class, $portability);
        $seed = $tracker->currentSeed('privacy', 'en');
        self::assertNotNull($seed);
        $portability->apply([
            ['slug' => 'privacy', 'locale' => 'en', 'title' => $seed->title, 'body' => $seed->body],
            ['slug' => 'notice', 'locale' => 'en', 'title' => 'Mine', 'body' => '<p>Mine</p>'],
        ], null, true);
        self::assertSame('r2', $this->found($kernel, 'privacy', 'en')->getSeedRevision(), 'An import equal to the seed is based on it');
        self::assertNull($this->found($kernel, 'notice', 'en')->getSeedRevision(), 'Any other import has an unknown base');
        self::assertNull($tracker->currentSeedHash('missing', 'en'));
    }

    public function testSanitizerIsIdempotentOnEverySeed(): void
    {
        $kernel    = $this->bootKernel(['locales' => ['en', 'es', 'de', 'fr', 'it', 'nl', 'pt']]);
        $container = $this->testContainer($kernel);
        $sanitizer = $container->get(LegalHtmlSanitizerInterface::class);
        $seeds     = $container->get(LegalSeedRegistry::class);
        self::assertInstanceOf(LegalHtmlSanitizerInterface::class, $sanitizer);
        self::assertInstanceOf(LegalSeedRegistry::class, $seeds);
        foreach (['notice', 'privacy', 'terms', 'cookies'] as $slug) {
            foreach (['en', 'es', 'de', 'fr', 'it', 'nl', 'pt'] as $locale) {
                $once = $sanitizer->sanitize((string) $seeds->find($slug, $locale)?->body);
                self::assertSame($once, $sanitizer->sanitize($once), $slug . '.' . $locale);
            }
        }
        foreach (['<p>a &amp; b</p>', '<a href="javascript&colon;alert(1)">x</a>', '<p>&lt;script&gt;</p>', "<p>x\u{00A0}y</p>"] as $html) {
            $once = $sanitizer->sanitize($html);
            self::assertSame($once, $sanitizer->sanitize($once));
        }
    }

    public function testRenderedSnapshotMatchesAnUneditedCopyUnderAnotherDefaultLocale(): void
    {
        // Instance A (default en) freezes its seed: the privacy text links /legal/cookies.
        $a = $this->versioningKernel(['locales' => ['en', 'es'], 'default_locale' => 'en', 'seed' => ['revision' => 'r1']]);
        self::assertSame(Command::SUCCESS, $this->tester($a, 'nowo:legal-kit:snapshot-seed')->execute([]));
        $snapshotBody = (string) ($this->service($a, SeedSnapshotStore::class)->entry('r1', 'privacy', 'en')['body'] ?? '');
        self::assertStringContainsString('href="/legal/cookies"', $snapshotBody);

        // Instance B (default es) stored the same, unedited English text: its links are /en/legal/cookies.
        $b       = $this->versioningKernel(['locales' => ['en', 'es'], 'default_locale' => 'es', 'seed' => ['revision' => 'r1']]);
        $tracker = $this->service($b, LegalSeedTracker::class);
        $seed    = $tracker->currentSeed('privacy', 'en');
        self::assertNotNull($seed);
        self::assertStringContainsString('href="/en/legal/cookies"', $seed->body);
        $row = new LegalDocument('privacy', 'en');
        $row->setTitle($seed->title);
        $row->setBody($seed->body);

        self::assertSame($this->service($b, SeedSnapshotStore::class)->hash('r1', 'privacy', 'en'), $tracker->documentHash($row));

        // The same holds the other way round, for a Spanish copy rendered under en (/es/legal/cookies vs /legal/cookies).
        $esA = $this->service($a, LegalSeedTracker::class)->currentSeed('privacy', 'es');
        $esB = $tracker->currentSeed('privacy', 'es');
        self::assertNotNull($esA);
        self::assertNotNull($esB);
        self::assertNotSame($esA->body, $esB->body);
        $hasher = $this->service($b, LegalSeedHasher::class);
        self::assertSame($hasher->hash($esA->title, $esA->body), $hasher->hash($esB->title, $esB->body));
    }

    public function testSnapshotCommand(): void
    {
        $kernel = $this->versioningKernel(['locales' => ['en', 'es'], 'seed' => ['revision' => 'r1']]);
        $tester = $this->tester($kernel, 'nowo:legal-kit:snapshot-seed');

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('8 page / locale entries', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('already exists', $tester->getDisplay());
        $codes = [];
        foreach ([['--force' => true], ['--revision' => 'other'], ['--revision' => '../x']] as $options) {
            $codes[] = $this->tester($kernel, 'nowo:legal-kit:snapshot-seed')->execute($options);
        }
        self::assertSame([0, 0, 1], $codes);
    }

    public function testUpgradeSeed(): void
    {
        $kernel    = $this->versioningKernel(['locales' => ['en', 'es', 'de'], 'seed' => ['revision' => 'new']]);
        $container = $this->testContainer($kernel);
        $tracker   = $container->get(LegalSeedTracker::class);
        $hasher    = $container->get(LegalSeedHasher::class);
        $store     = $container->get(SeedSnapshotStore::class);
        self::assertInstanceOf(LegalSeedTracker::class, $tracker);
        self::assertInstanceOf(LegalSeedHasher::class, $hasher);
        self::assertInstanceOf(SeedSnapshotStore::class, $store);

        // The old revision: terms (en, es) and privacy (en); notice (en) only exists as a row.
        $store->write('old', [
            'terms'   => ['en' => ['title' => 'Old terms', 'body' => '<p>Old terms</p>'], 'es' => ['title' => 'Viejas', 'body' => '<p>Viejas</p>']],
            'privacy' => ['en' => ['title' => 'Old privacy', 'body' => '<p>Old privacy</p>']],
            'cookies' => ['de' => ['title' => 'Cookies', 'body' => '<p>Same?</p>']],
        ]);
        $this->store($kernel, 'terms', 'en', 'Old terms', "<p>Old\n terms</p>");             // unedited, base from --from → replaced
        $this->store($kernel, 'privacy', 'en', 'Old privacy', '<p>Old privacy, edited</p>');  // edited → outdated
        $newNotice = $tracker->currentSeed('notice', 'en');
        self::assertNotNull($newNotice);
        $this->store($kernel, 'notice', 'en', $newNotice->title, $newNotice->body);          // already the new text → current
        $this->storeWithBase($kernel, 'cookies', 'es', 'Cookies', '<p>Base</p>', 'mid', $hasher->hash('Cookies', '<p>Base</p>')); // known hash → replaced
        $this->storeWithBase($kernel, 'notice', 'es', 'Aviso', '<p>Mío</p>', 'mid', null);  // base revision without snapshot → outdated

        $tester = $this->tester($kernel, 'nowo:legal-kit:upgrade-seed');
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Stored copies without a seed base: 3', $tester->getDisplay());
        self::assertSame(Command::INVALID, $tester->execute(['--from' => '../old']));

        self::assertSame(Command::SUCCESS, $tester->execute(['--from' => 'old', '--dry-run' => true, '--requires-acceptance' => true]));
        self::assertStringContainsString('Dry run', $tester->getDisplay());
        self::assertStringContainsString('Version published: terms v2 (acceptance required)', $tester->getDisplay());
        self::assertSame("<p>Old\n terms</p>", $this->found($kernel, 'terms', 'en')->getBody(), 'The dry run wrote nothing');
        self::assertNull($this->found($kernel, 'terms', 'en')->getSeedRevision());

        self::assertSame(UpgradeSeedCommand::EXIT_OUTDATED, $tester->execute(['--from' => 'old', '--requires-acceptance' => true, '--fail-on-outdated' => true]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('replaced: 2, outdated: 2, current: 1, seed_changed: 2, seed_same: 0, seed_unknown: 5, no_seed: 0', $display);
        self::assertStringContainsString('Outdated copies to review in the admin: 2.', $display);

        $terms = $this->found($kernel, 'terms', 'en');
        $seed  = $tracker->currentSeed('terms', 'en');
        self::assertNotNull($seed);
        self::assertSame($seed->body, $terms->getBody());
        self::assertSame('new', $terms->getSeedRevision());
        self::assertSame('old', $this->found($kernel, 'privacy', 'en')->getSeedRevision(), 'The outdated copy is based on --from');
        self::assertSame('<p>Old privacy, edited</p>', $this->found($kernel, 'privacy', 'en')->getBody());
        self::assertSame('new', $this->found($kernel, 'notice', 'en')->getSeedRevision());
        self::assertSame('new', $this->found($kernel, 'cookies', 'es')->getSeedRevision());
        self::assertSame('mid', $this->found($kernel, 'notice', 'es')->getSeedRevision());

        $versions = $container->get(LegalPageVersions::class);
        self::assertInstanceOf(LegalPageVersions::class, $versions);
        self::assertSame(['cookies' => [2, 0], 'notice' => [2, 0], 'privacy' => [2, 2], 'terms' => [2, 2]], $versions->requirements());
        self::assertSame('upgrade', $versions->latest('terms')?->getSource());

        // Idempotent: nothing more to publish or replace.
        self::assertSame(Command::SUCCESS, $tester->execute(['--requires-acceptance' => true, '--from' => 'old']));
        self::assertStringContainsString('replaced: 0', $tester->getDisplay());
        self::assertSame(2, $versions->current('terms'));

        // Without a flag: no version.
        $versionsBefore = $versions->requirements();
        self::assertSame(Command::SUCCESS, $tester->execute(['--from' => 'old']));
        self::assertSame($versionsBefore, $versions->requirements());
    }

    public function testUpgradeSeedNewVersionWithoutAcceptanceAndSummaries(): void
    {
        $kernel = $this->versioningKernel(['locales' => ['en']], [], ['framework' => ['translator' => ['paths' => [__DIR__ . '/../Support/translations']]]]);
        $tester = $this->tester($kernel, 'nowo:legal-kit:upgrade-seed');

        self::assertSame(Command::SUCCESS, $tester->execute(['--new-version' => true]));
        $versions = $this->service($kernel, LegalPageVersions::class);
        self::assertSame([2, 0], $versions->requirements()['terms'] ?? null);
        self::assertSame(['en' => 'We clarified the acceptable use.'], $versions->latest('terms')?->getChangeSummary());
        self::assertSame([], $versions->latest('notice')?->getChangeSummary());
    }

    public function testDoctorAndTwig(): void
    {
        $kernel = $this->versioningKernel(['locales' => ['en'], 'seed' => ['revision' => 'new'], 'pages' => ['cookies' => ['acceptance' => 'acknowledge']]]);
        $this->store($kernel, 'terms', 'en', 'T', '<p>T</p>');
        $this->storeWithBase($kernel, 'privacy', 'en', 'P', '<p>P</p>', 'old', null);

        $doctor = $this->tester($kernel, 'nowo:legal-kit:doctor');
        $doctor->execute([]);
        self::assertStringContainsString('Stored legal copies without a seed base: 1', $doctor->getDisplay());
        self::assertStringContainsString('cookie consent belongs to', $doctor->getDisplay());

        $twig = $this->testContainer($kernel)->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        self::assertSame('1|privacy en old /admin/legal/privacy/en/compare', $twig->createTemplate('{{ nowo_legal_kit_outdated_count() }}{% for o in nowo_legal_kit_outdated() %}|{{ o.slug }} {{ o.locale }} {{ o.seed_revision }} {{ o.compare_url }}{% endfor %}')->render());
    }

    public function testPublicPageShowsTheVersion(): void
    {
        $kernel = $this->versioningKernel(['locales' => ['en']]);
        $client = $this->client($kernel);
        $client->request('GET', '/legal/terms');
        self::assertStringContainsString('Version 1', (string) $client->getResponse()->getContent());

        $this->service($kernel, LegalPageVersions::class)->publish('terms', true, 'editor');
        $this->store($kernel, 'terms', 'en', 'Terms', '<p>Ours</p>');
        $client->request('GET', '/legal/terms');
        self::assertMatchesRegularExpression('/Last updated: \d{4}-\d{2}-\d{2} · Version 2/', (string) $client->getResponse()->getContent());
    }

    public function testCatalogCarriesTheModes(): void
    {
        $catalog = $this->testContainer($this->bootKernel())->get(LegalPageCatalogInterface::class);
        self::assertInstanceOf(LegalPageCatalogInterface::class, $catalog);
        self::assertSame(['accept', 'acknowledge', 'none', 'none'], [$catalog->page('terms')->acceptance, $catalog->page('privacy')->acceptance, $catalog->page('notice')->acceptance, $catalog->page('cookies')->acceptance]);
        self::assertTrue($catalog->page('terms')->requiresAcceptance());
        self::assertInstanceOf(LegalLocales::class, $this->testContainer($this->bootKernel())->get(LegalLocales::class));
    }

    public function testUpgradePagesWithoutSeed(): void
    {
        $kernel = $this->versioningKernel(['locales' => ['en', 'es'], 'pages' => ['extra' => ['path' => 'extra']]]);
        $this->storeWithBase($kernel, 'extra', 'en', 'Extra', '<p>Extra</p>', 'r0', null);
        $tester = $this->tester($kernel, 'nowo:legal-kit:upgrade-seed');

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('no_seed: 1', $tester->getDisplay());
        self::assertSame('<p>Extra</p>', $this->found($kernel, 'extra', 'en')->getBody());
    }

    public function testUpgradeRollsBackOnFailure(): void
    {
        $kernel = $this->versioningKernel(['locales' => ['en']]);
        $this->storeWithBase($kernel, 'notice', 'en', 'Old', '<p>Old</p>', 'old', $this->service($kernel, LegalSeedHasher::class)->hash('Old', '<p>Old</p>'));
        $events     = [];
        $dispatcher = $this->service($kernel, EventDispatcherInterface::class);
        $dispatcher->addListener(LegalVersionPublishedEvent::class, static function (LegalVersionPublishedEvent $event) use (&$events): void {
            $events[] = $event->slug;
        });
        // The second page's flush fails inside the transaction.
        $failing = new class {
            public int $flushes = 0;

            public function postFlush(): void
            {
                if (++$this->flushes === 3) {
                    throw new RuntimeException('Database down');
                }
            }
        };
        $this->entityManager($kernel)->getEventManager()->addEventListener([Events::postFlush], $failing);

        try {
            $this->service($kernel, LegalSeedUpgrader::class)->run(null, LegalSeedUpgrader::VERSIONS_NEW);
            self::fail('The exception propagates');
        } catch (RuntimeException $exception) {
            self::assertSame('Database down', $exception->getMessage());
        }
        $this->entityManager($kernel)->getEventManager()->removeEventListener([Events::postFlush], $failing);
        self::assertSame([], $events, 'No event for a version that was rolled back');
        self::assertSame('<p>Old</p>', $this->found($kernel, 'notice', 'en')->getBody(), 'Rolled back');
        self::assertSame([], $this->service($kernel, LegalPageVersions::class)->requirements());
    }

    public function testSnapshotDirectoryThatCannotBeCreated(): void
    {
        $file = sys_get_temp_dir() . '/nowo-legal-kit-not-a-dir';
        file_put_contents($file, 'x');
        $store = new SeedSnapshotStore($file . '/snapshots', $this->service($this->versioningKernel(), LegalSeedHasher::class));
        try {
            $store->write('r1', []);
            self::fail('A file in the way');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Cannot create the snapshot directory', $exception->getMessage());
        } finally {
            unlink($file);
        }
    }

    public function testForceRefusesASymlinkedRevision(): void
    {
        $kernel  = $this->versioningKernel(['locales' => ['en'], 'seed' => ['revision' => 'r1']]);
        $outside = sys_get_temp_dir() . '/nowo-legal-kit-victim-' . bin2hex(random_bytes(4));
        mkdir($outside);
        file_put_contents($outside . '/victim.html', 'keep me');
        mkdir(self::snapshotsDir(), 0o775, true);
        symlink($outside, self::snapshotsDir() . '/r1');
        try {
            $tester = $this->tester($kernel, 'nowo:legal-kit:snapshot-seed');
            self::assertSame(1, $tester->execute(['--force' => true]));
            self::assertStringContainsString('not a plain directory', $tester->getDisplay());
            self::assertFileExists($outside . '/victim.html', 'Nothing outside the snapshot directory is deleted');
        } finally {
            @unlink(self::snapshotsDir() . '/r1');
            @unlink($outside . '/victim.html');
            @rmdir($outside);
        }
    }
}
