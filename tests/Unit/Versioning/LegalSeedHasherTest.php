<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\Versioning;

use Nowo\LegalKitBundle\Catalog\ConfiguredLegalPageCatalog;
use Nowo\LegalKitBundle\Sanitizer\DomAllowlistSanitizer;
use Nowo\LegalKitBundle\Versioning\LegalSeedHasher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function strlen;

/**
 * legalkit-seed-v1: normalisation, edits detected, LegalKit links canonical whatever the default locale.
 */
final class LegalSeedHasherTest extends TestCase
{
    private function hasher(?string $host = 'beacon.example.com'): LegalSeedHasher
    {
        $catalog = new ConfiguredLegalPageCatalog([
            'privacy' => ['path' => 'privacy'],
            'cookies' => ['path' => 'cookie-policy'],
        ]);

        return new LegalSeedHasher(new DomAllowlistSanitizer(), $catalog, '/legal', $host);
    }

    public function testWhitespaceNbspAndLineEndingsDoNotCount(): void
    {
        $hasher = $this->hasher();
        $a      = $hasher->hash(' Terms  of use ', "<h1>Terms</h1>\r\n<p>Use   the&nbsp;service.</p>");
        $b      = $hasher->hash('Terms of use', "<h1>Terms</h1>\n\n  <p>Use the service.</p>");

        self::assertSame($a, $b);
        self::assertSame(64, strlen($a));
    }

    public function testEditsCount(): void
    {
        $hasher = $this->hasher();
        $base   = $hasher->hash('Terms', '<p>Hello %brand%</p>');

        self::assertNotSame($base, $hasher->hash('Terms of use', '<p>Hello %brand%</p>'), 'A title-only edit is an edit');
        self::assertNotSame($base, $hasher->hash('Terms', '<p>Hello Acme</p>'), 'A rendered token is an edit');
        self::assertNotSame($base, $hasher->hash('Terms', '<p>Hello %brand%.</p>'));
        self::assertSame($base, $hasher->hash('Terms', '<p onclick="x()">Hello %brand%</p><script>x()</script>'), 'The sanitizer runs first');
    }

    /**
     * @return iterable<string, array{0: string, 1: ?string}>
     */
    public static function links(): iterable
    {
        yield 'default locale (unprefixed)' => ['/legal/cookie-policy', 'cookies'];
        yield 'other locale (prefixed)' => ['/en/legal/cookie-policy', 'cookies'];
        yield 'region locale' => ['/pt-BR/legal/cookie-policy', 'cookies'];
        yield 'trailing slash' => ['/es/legal/privacy/', 'privacy'];
        yield 'absolute on the host' => ['https://beacon.example.com/es/legal/privacy', 'privacy'];
        yield 'absolute, host case' => ['http://BEACON.example.com/legal/privacy', 'privacy'];
        yield 'other host' => ['https://evil.example/legal/privacy', null];
        yield 'query' => ['/legal/privacy?x=1', null];
        yield 'user info' => ['https://u:p@beacon.example.com/legal/privacy', null];
        yield 'other scheme' => ['ftp://beacon.example.com/legal/privacy', null];
        yield 'unknown page' => ['/legal/terms', null];
        yield 'deeper path' => ['/x/y/legal/privacy', null];
        yield 'relative without slash' => ['legal/privacy', null];
        yield 'broken URL' => ['http:///legal/privacy', null];
    }

    #[DataProvider('links')]
    public function testLinkedSlug(string $href, ?string $slug): void
    {
        self::assertSame($slug, $this->hasher()->linkedSlug($href));
    }

    public function testAbsoluteLinksNeedAKnownHost(): void
    {
        self::assertNull($this->hasher(null)->linkedSlug('https://beacon.example.com/legal/privacy'));
        self::assertSame('privacy', $this->hasher(null)->linkedSlug('/legal/privacy'));
    }

    public function testRenderedLinksOfAnyDefaultLocaleHashAlike(): void
    {
        $hasher = $this->hasher();
        $en     = '<p><a href="/legal/cookie-policy">Cookies</a> · <a href="/legal/cookie-policy#manage" data-nowo-open-consent>Manage</a></p>';
        $es     = '<p><a href="/en/legal/cookie-policy">Cookies</a> · <a href="https://beacon.example.com/en/legal/cookie-policy#manage" data-nowo-open-consent>Manage</a></p>';

        self::assertSame($hasher->hash('Privacy', $en), $hasher->hash('Privacy', $es));
        self::assertStringContainsString('href="%legal_link:cookies%#manage"', $hasher->normalizeBody($es));
        self::assertNotSame($hasher->hash('Privacy', $en), $hasher->hash('Privacy', '<p><a href="/legal/privacy">Cookies</a> · <a href="/legal/cookie-policy#manage" data-nowo-open-consent>Manage</a></p>'), 'Another page is another text');
        self::assertNotSame($hasher->hash('Privacy', $en), $hasher->hash('Privacy', '<p><a href="#">Cookies</a> · <a href="/legal/cookie-policy#manage" data-nowo-open-consent>Manage</a></p>'));
    }

    public function testWithoutACatalogLinksStayAsTheyAre(): void
    {
        $hasher = new LegalSeedHasher(new DomAllowlistSanitizer());

        self::assertNull($hasher->linkedSlug('/legal/privacy'));
        self::assertSame('<p><a href="/legal/privacy">x</a></p>', $hasher->normalizeBody('<p><a href="/legal/privacy">x</a></p>'));
    }

    public function testNormalizeText(): void
    {
        self::assertSame('a b c', LegalSeedHasher::normalizeText("  a \t b\u{00A0}\nc "));
        self::assertSame("x \xff y", LegalSeedHasher::normalizeText("x  \xff  y"), 'Invalid UTF-8 falls back to ASCII whitespace');
    }
}
