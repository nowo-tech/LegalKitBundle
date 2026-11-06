<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\Sanitizer;

use Nowo\LegalKitBundle\Catalog\ConfiguredLegalPageCatalog;
use Nowo\LegalKitBundle\Sanitizer\DomAllowlistSanitizer;
use Nowo\LegalKitBundle\Sanitizer\UrlPolicy;
use Nowo\LegalKitBundle\Versioning\LegalSeedHasher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_rand;
use function implode;
use function mt_rand;
use function mt_srand;
use function shuffle;
use function str_contains;

/**
 * `withoutAnchorIds(on(x)) === off(x)`, on sanitised output and through the seed hash: removing the anchor ids must give
 * back what the sanitiser prints with the option empty, whatever the attribute values hold. Appendix HB, HB-L3.
 */
final class AnchorIdStripPropertyTest extends TestCase
{
    private const array ANCHORS = ['retention', 'rights'];

    private const array EXTRAS = ['p' => ['title', 'data-x'], 'h2' => ['title', 'data-x', 'aria-label'], 'section' => ['title']];

    /**
     * @return iterable<string, array{bool, array<string, list<string>>}>
     */
    public static function configurations(): iterable
    {
        yield 'HTML5 where PHP has it' => [true, []];
        yield 'HTML5 with extras' => [true, self::EXTRAS];
        yield 'libxml' => [false, []];
        yield 'libxml with extras' => [false, self::EXTRAS];
    }

    /**
     * @param array<string, list<string>> $extras
     */
    #[DataProvider('configurations')]
    public function testStrippingGivesTheOutputWithoutTheOption(bool $html5, array $extras): void
    {
        $on      = new DomAllowlistSanitizer(new UrlPolicy(), $extras, null, $html5, self::ANCHORS);
        $off     = new DomAllowlistSanitizer(new UrlPolicy(), $extras, null, $html5);
        $catalog = new ConfiguredLegalPageCatalog(['notice' => [], 'privacy' => [], 'terms' => [], 'cookies' => []]);
        $hashOn  = new LegalSeedHasher($on, $catalog);
        $hashOff = new LegalSeedHasher($off, $catalog);
        $tags    = ['h1', 'h2', 'h3', 'h4', 'section', 'div', 'p', 'a', 'img', 'pre', 'code', 'span', 'table', 'td', 'custom-x', 'svg', 'template', 'li', 'ul'];
        $values  = ['<h2 id=', '<section id=', '<h2 id=&quot;x&quot;', 'x', '', 'a"b', "<h3 id='x'", '&lt;h2 id=', '>', '<', 'retention', '/x'];
        $attrs   = ['title', 'alt', 'data-x', 'aria-label', 'href', 'src', 'id', 'name', 'xmlns', 'class'];
        mt_srand(20261107);

        for ($round = 0; $round < 2000; ++$round) {
            $html = '';
            for ($count = mt_rand(1, 14); $count > 0; --$count) {
                $tag   = $tags[array_rand($tags)];
                $parts = [];
                if (mt_rand(0, 1) === 1) {
                    $parts[] = 'class="legal-anchor--' . self::ANCHORS[array_rand(self::ANCHORS)] . '"';
                }
                for ($q = mt_rand(0, 3); $q > 0; --$q) {
                    $value   = $values[array_rand($values)];
                    $attr    = $attrs[array_rand($attrs)];
                    $parts[] = str_contains($value, '"') ? $attr . "='" . $value . "'" : $attr . '="' . $value . '"';
                }
                shuffle($parts);
                $html .= '<' . $tag . ' ' . implode(' ', $parts) . '>' . (mt_rand(0, 2) > 0 ? 'txt &lt;h2 id="q"' : '') . (mt_rand(0, 1) === 1 ? '</' . $tag . '>' : '');
            }

            self::assertSame($off->sanitize($html), DomAllowlistSanitizer::withoutAnchorIds($on->sanitize($html)), $html);
            self::assertSame($hashOff->hash('t', $html), $hashOn->hashWithoutAnchorIds('t', $html), $html);
        }
    }

    public function testValueThatLooksLikeAnAnchorIsLeftAlone(): void
    {
        $html = (new DomAllowlistSanitizer(html5: true))->sanitize('<a title="<h2 id=">NEW CLAUSE</a><a title="">x</a>');

        self::assertSame($html, DomAllowlistSanitizer::withoutAnchorIds($html));
    }
}
