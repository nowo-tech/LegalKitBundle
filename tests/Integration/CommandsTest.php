<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Integration;

use Nowo\LegalKitBundle\Placeholder\LegalPlaceholderScanner;
use Nowo\LegalKitBundle\Tests\Support\KernelTestCase;
use Nowo\LegalKitBundle\Tests\Support\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function file_get_contents;
use function file_put_contents;
use function json_decode;
use function json_encode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Console commands: placeholder gate, adapt-seed, export / import, materialise, doctor and the legacy aliases.
 */
final class CommandsTest extends KernelTestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testCheckPlaceholdersFailsOnTheGenericSeedAndPassesOnceReplaced(): void
    {
        $kernel = $this->bootKernel(['locales' => ['en'], 'brand_name' => 'Acme']);
        $tester = $this->tester($kernel, 'nowo:legal-kit:check-placeholders');

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Operator legal name', $tester->getDisplay());
        self::assertStringContainsString('Counsel review', $tester->getDisplay());

        foreach (['notice', 'privacy', 'terms', 'cookies'] as $slug) {
            $this->store($kernel, $slug, 'en', 'Real ' . $slug, '<p>Real text of %brand%</p>');
        }
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No generic legal placeholders', $tester->getDisplay());
    }

    public function testCheckPlaceholdersJsonReportsUnresolvedTokens(): void
    {
        $kernel = $this->bootKernel(['locales' => ['en', 'es']]);
        $tester = $this->tester($kernel, 'app:legal:check-placeholders');

        self::assertSame(Command::FAILURE, $tester->execute(['--locale' => 'es', '--format' => 'json']));
        $json = json_decode($tester->getDisplay(), true);
        self::assertIsArray($json);
        self::assertGreaterThan(0, $json['count']);
        $kinds = array_unique(array_column($json['hits'], 'kind'));
        self::assertContains('token', $kinds, '%brand% without brand_name is reported');
        self::assertContains('marker', $kinds);
        self::assertSame(['es'], array_values(array_unique(array_column($json['hits'], 'locale'))));
    }

    public function testCheckPlaceholdersValidatesItsOptions(): void
    {
        $tester = $this->tester($this->bootKernel(), 'nowo:legal-kit:check-placeholders');

        self::assertSame(Command::INVALID, $tester->execute(['--format' => 'xml']));
        self::assertSame(Command::INVALID, $tester->execute(['--locale' => 'fr']));
    }

    public function testCustomMarkersOnly(): void
    {
        $kernel = $this->bootKernel(['locales' => ['en'], 'brand_name' => 'Acme', 'placeholders' => ['builtin_markers' => false, 'extra_markers' => ['TODO-LEGAL']]]);
        $tester = $this->tester($kernel, 'nowo:legal-kit:check-placeholders');
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $this->store($kernel, 'terms', 'en', 'Terms', '<p>TODO-LEGAL</p>');
        $scanner = $kernel->getContainer()->get('test.service_container')->get(LegalPlaceholderScanner::class);
        self::assertInstanceOf(LegalPlaceholderScanner::class, $scanner);
        $scanner->reset();
        self::assertSame(Command::FAILURE, $tester->execute([]));
    }

    public function testAdaptSeedReplacesMarkers(): void
    {
        $kernel = $this->bootKernel(['locales' => ['en', 'es']]);
        $map    = $this->file(json_encode(['en' => [['Operator legal name — replace', 'Acme Ltd']]]));
        $out    = $this->file('');
        $tester = $this->tester($kernel, 'app:ops-seed:adapt-legal');

        self::assertSame(Command::SUCCESS, $tester->execute(['--replacements' => $map, '--output' => $out]));
        $documents = json_decode((string) file_get_contents($out), true);
        self::assertIsArray($documents);
        self::assertCount(8, $documents);
        self::assertStringContainsString('Acme Ltd', $documents[0]['body']);
        self::assertSame('en', $documents[0]['locale']);

        self::assertSame(Command::FAILURE, $tester->execute(['--replacements' => $map, '--require-clear-markers' => true]));
        self::assertStringContainsString('Marker still present', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['--replacements' => $this->file('{}')]));
        self::assertStringContainsString('"slug": "notice"', $tester->getDisplay());
    }

    public function testAdaptSeedRejectsBadInput(): void
    {
        $tester = $this->tester($this->bootKernel(), 'nowo:legal-kit:adapt-seed');

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertSame(Command::FAILURE, $tester->execute(['--replacements' => '/nonexistent.json']));
        self::assertSame(Command::FAILURE, $tester->execute(['--replacements' => $this->file(' ')]));
        self::assertSame(Command::FAILURE, $tester->execute(['--replacements' => $this->file('{oops')]));
        self::assertSame(Command::FAILURE, $tester->execute(['--replacements' => $this->file('[["a","b"]]')]));
        self::assertSame(Command::FAILURE, $tester->execute(['--replacements' => $this->file('{"en": "x"}')]));
        self::assertSame(Command::FAILURE, $tester->execute(['--replacements' => $this->file('{"en": [["a"]]}')]));
        self::assertSame(Command::FAILURE, $tester->execute(['--replacements' => $this->file('{}'), '--output' => '/nonexistent/dir/out.json']));
    }

    public function testExportImportRoundTrip(): void
    {
        $kernel = $this->bootKernel();
        $this->store($kernel, 'privacy', 'es', 'Privacidad', '<p>Datos de %brand%</p>');
        $out    = $this->file('');
        $export = $this->tester($kernel, 'nowo:legal-kit:export');

        self::assertSame(Command::SUCCESS, $export->execute(['--output' => $out]));
        self::assertSame([['slug' => 'privacy', 'locale' => 'es', 'title' => 'Privacidad', 'body' => '<p>Datos de %brand%</p>']], json_decode((string) file_get_contents($out), true));
        self::assertSame(Command::SUCCESS, $export->execute([]));
        self::assertStringContainsString('"locale": "es"', $export->getDisplay());
        self::assertSame(Command::FAILURE, $export->execute(['--output' => '/nonexistent/dir/out.json']));

        $import = $this->tester($kernel, 'nowo:legal-kit:import');
        $file   = $this->file((string) json_encode(['legal_documents' => [
            ['slug' => 'privacy', 'locale' => 'es', 'title' => 'Privacidad 2', 'body' => '<p>Nuevo</p><script>x</script>'],
            ['slug' => 'terms', 'locale' => 'de', 'title' => 'AGB', 'body' => '<p>Bedingungen</p>'],
        ]]));
        self::assertSame(Command::SUCCESS, $import->execute(['file' => $file, '--dry-run' => true]));
        self::assertNull($this->find($kernel, 'terms', 'de'));

        self::assertSame(Command::SUCCESS, $import->execute(['file' => $file]));
        self::assertSame('<p>Nuevo</p>', $this->find($kernel, 'privacy', 'es')?->getBody());
        self::assertSame('AGB', $this->find($kernel, 'terms', 'de')?->getTitle());

        self::assertSame(Command::FAILURE, $import->execute(['file' => '/nonexistent.json']));
        self::assertSame(Command::FAILURE, $import->execute(['file' => $this->file('[{"slug": "imprint", "locale": "en", "title": "x", "body": "<p>x</p>"}]')]));
        self::assertStringContainsString('legal_documents[0].slug', $import->getDisplay());
        self::assertSame(Command::FAILURE, $import->execute(['file' => $this->file('not json')]));
    }

    public function testSeedMaterialisesMissingRowsOnly(): void
    {
        $kernel = $this->bootKernel(['locales' => ['en', 'es'], 'seed' => ['materialize' => true]]);
        $this->store($kernel, 'notice', 'es', 'Mío', '<p>Mío</p>');
        $tester = $this->tester($kernel, 'nowo:legal-kit:seed');

        self::assertSame(Command::SUCCESS, $tester->execute(['--missing-only' => true]));
        self::assertStringContainsString('7 legal document(s) inserted', $tester->getDisplay());
        self::assertSame('Mío', $this->find($kernel, 'notice', 'es')?->getTitle());
        self::assertSame('Privacy policy', $this->find($kernel, 'privacy', 'en')?->getTitle());

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('0 legal document(s) inserted', $tester->getDisplay());
    }

    public function testSeedIsANoOpWithoutMaterialize(): void
    {
        $kernel = $this->bootKernel(['locales' => ['en']]);
        $tester = $this->tester($kernel, 'nowo:legal-kit:seed');

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('seed.materialize is false', $tester->getDisplay());
        self::assertNull($this->find($kernel, 'notice', 'en'));

        self::assertSame(Command::SUCCESS, $tester->execute(['--force' => true]));
        self::assertNotNull($this->find($kernel, 'notice', 'en'));
    }

    public function testDoctorWithoutIntegrations(): void
    {
        $tester = $this->tester($this->bootKernel(), 'nowo:legal-kit:doctor');

        self::assertSame(Command::SUCCESS, $tester->execute(['--strict' => true]));
        self::assertStringContainsString('LegalKit integration OK (4 page(s), 3 locale(s))', $tester->getDisplay());
    }

    public function testDoctorReportsAProtectedPublicPrefix(): void
    {
        $kernel = $this->bootKernel([], [], true, ['security' => ['access_control' => [['path' => '^/', 'roles' => 'ROLE_USER']]]]);
        $tester = $this->tester($kernel, 'nowo:legal-kit:doctor');

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('not anonymous-readable', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['--strict' => true]));
    }

    public function testDoctorReportsSeedsWithoutMarkers(): void
    {
        $tester = $this->tester($this->bootKernel(['locales' => ['en', 'ca']]), 'nowo:legal-kit:doctor');

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No legal notice seed for locale "ca"', $tester->getDisplay());
    }

    public function testLegacyAliasesCanBeDisabled(): void
    {
        $application = new Application($this->bootKernel(['console' => ['legacy_aliases' => false]]));
        self::assertFalse($application->has('app:legal:check-placeholders'));
        self::assertTrue($application->has('nowo:legal-kit:check-placeholders'));
    }

    private function tester(TestKernel $kernel, string $name): CommandTester
    {
        $application = new Application($kernel);

        return new CommandTester($application->find($name));
    }

    private function file(string $contents): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'legal-kit-');
        file_put_contents($file, $contents);
        $this->files[] = $file;

        return $file;
    }
}
