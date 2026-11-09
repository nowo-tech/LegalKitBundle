<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Functional;

use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceManager;
use Nowo\LegalKitBundle\Command\VerifyAcceptanceHashCommand;
use Nowo\LegalKitBundle\Document\LegalDocumentResolver;
use Nowo\LegalKitBundle\Document\ResolvedLegalDocument;
use Nowo\LegalKitBundle\Tests\Support\TestBrandProvider;
use Nowo\LegalKitBundle\Tests\Support\TestKernel;
use Nowo\LegalKitBundle\Tests\Support\VersioningTestCase;
use Symfony\Component\Console\Command\Command;

use function str_repeat;

/**
 * Audit IW-L1: an acceptance recorded before a brand rename can still be checked with `--brand-name`.
 */
final class BrandRenameVerifyTest extends VersioningTestCase
{
    public function testAHashRecordedUnderAPastNameIsVerifiedWithBrandName(): void
    {
        $kernel = $this->acceptanceKernel([], ['app_routes', 'brand_provider']);
        $this->storeWithBase($kernel, 'privacy', 'en', 'Privacy of %brand%', '<p>Data of %brand% users.</p>', null, null);
        $provider = $this->service($kernel, TestBrandProvider::class);

        $provider->name = 'Old <Name> & "Co"';
        $recorded       = LegalAcceptanceManager::contentHash($this->resolved($kernel));
        $provider->name = 'New Name';
        $tester         = $this->tester($kernel, VerifyAcceptanceHashCommand::NAME);
        $args           = ['slug' => 'privacy', 'locale' => 'en', 'hash' => $recorded];

        self::assertSame(Command::FAILURE, $tester->execute($args), 'The current name does not match');
        self::assertStringContainsString('--brand-name', $tester->getDisplay());

        self::assertSame(Command::FAILURE, $tester->execute($args + ['--brand-name' => ['Other']]));
        self::assertSame(Command::SUCCESS, $tester->execute($args + ['--brand-name' => ['Other', 'Old <Name> & "Co"']]));
        self::assertStringContainsString('with the brand name "Old <Name>', $tester->getDisplay());

        $current = LegalAcceptanceManager::contentHash($this->resolved($kernel));
        self::assertSame(Command::SUCCESS, $tester->execute(['hash' => $current] + $args + ['--brand-name' => ['Other']]), 'The current text is checked first');
        self::assertStringContainsString('matches the current text.', $tester->getDisplay());

        self::assertSame('New Name', $provider->name, 'The command never changes the brand');
        self::assertSame($current, LegalAcceptanceManager::contentHash($this->resolved($kernel)));
    }

    public function testInvalidBrandNamesAreRefused(): void
    {
        $kernel = $this->acceptanceKernel([], ['app_routes', 'brand_provider']);
        $tester = $this->tester($kernel, VerifyAcceptanceHashCommand::NAME);
        $args   = ['slug' => 'privacy', 'locale' => 'en', 'hash' => str_repeat('a', 64)];

        foreach (['', '  ', "Acme\nInc", "Acme\u{202E}", str_repeat('x', VerifyAcceptanceHashCommand::BRAND_NAME_MAX + 1), "\xC3\x28"] as $name) {
            self::assertSame(Command::INVALID, $tester->execute($args + ['--brand-name' => [$name]]));
        }
        self::assertSame(Command::INVALID, $tester->execute(['slug' => 'nope'] + $args + ['--brand-name' => ['Acme']]));
    }

    public function testAPastNameAlsoWinsOverBrandNameWithoutAProvider(): void
    {
        $kernel = $this->acceptanceKernel(['brand_name' => 'Current'], ['app_routes']);
        $past   = $this->service($kernel, LegalDocumentResolver::class)->resolve('privacy', 'en', 'Past <b>');
        self::assertInstanceOf(ResolvedLegalDocument::class, $past);
        self::assertStringContainsString('Past &lt;b&gt;', $past->body);
        self::assertStringNotContainsString('Current', $past->body);
        self::assertStringContainsString('Current', $this->resolved($kernel)->body);
    }

    private function resolved(TestKernel $kernel): ResolvedLegalDocument
    {
        $document = $this->service($kernel, LegalDocumentResolver::class)->resolve('privacy', 'en');
        self::assertInstanceOf(ResolvedLegalDocument::class, $document);

        return $document;
    }
}
