<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\Sanitizer;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Nowo\LegalKitBundle\Sanitizer\DomAllowlistSanitizer;
use Nowo\LegalKitBundle\Sanitizer\NestingEstimator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\TestCase;

use function array_rand;
use function mt_rand;
use function mt_srand;
use function str_repeat;

use const PHP_INT_MAX;

/**
 * The estimate that keeps very deep text away from the HTML5 parser: what must be refused, what must not, and a fuzz
 * against the real parser. Each refusal case below crashes PHP 8.4.27 if it reaches the parser, so a regression here
 * can take the test process down.
 */
final class NestingEstimatorTest extends TestCase
{
    private const int LIMIT = DomAllowlistSanitizer::MAX_NESTING_DEPTH;

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        $n = 60000;

        yield 'plain' => [str_repeat('<div>', $n)];
        yield 'wrong closer' => [str_repeat('<div></span>', $n)];
        yield 'closer in an attribute' => [str_repeat('<div title="</div>">', $n)];
        yield 'closer in a comment' => [str_repeat('<div><!--</div>-->', $n)];
        yield 'quote inside an unquoted value hides nothing' => ['<div x=a">' . str_repeat('<div>', $n) . '"'];
        yield 'comment closed at once' => ['<!-->' . str_repeat('<div>', $n) . '-->'];
        yield 'comment closed at once, one dash' => ['<!--->' . str_repeat('<div>', $n) . '-->'];
        yield 'comment closed with a bang' => ['<!-- --!>' . str_repeat('<div>', $n)];
        yield 'comment opener in an attribute' => ['<div title="<!--">' . str_repeat('<div>', $n) . '-->'];
        yield 'formatting end tag cannot close a block' => [str_repeat('<b><div></b>', $n)];
        yield 'block end tag stops at a table' => [str_repeat('<div><table></div>', $n)];
        yield 'block end tag in raw text' => [str_repeat('<div><style></div></style>', $n)];
        yield 'block end tag in a textarea' => [str_repeat('<div><textarea></div></textarea>', $n)];
        yield 'span cannot close through a block' => [str_repeat('<span><div></span>', $n)];
        yield 'formatting survives its block' => [str_repeat('<div><b a="1"></div>', $n) . 'x'];
        yield 'heading end tag closes the nearest heading' => [str_repeat('<h1><div><h2></h1>', $n)];
        yield 'second link in a block' => [str_repeat('<a><div>', $n)];
        yield 'self-closing is ignored in HTML' => [str_repeat('<div/>', $n)];
        yield 'self-closing after leaving svg' => ['<svg><div>' . str_repeat('<x/>', $n)];
        yield 'self-closing inside foreignObject' => ['<svg><foreignObject>' . str_repeat('<x/>', $n)];
        yield 'optional end tags count in svg' => ['<svg>' . str_repeat('<option>', $n)];
        yield 'raw text in select is not raw' => ['<select><style><input>' . str_repeat('<div>', $n) . '</style>'];
        yield 'plaintext in select is not plaintext' => ['<select><plaintext><input>' . str_repeat('<div>', $n)];
        yield 'raw text after leaving svg' => ['<svg><p><style><a title="</style>' . str_repeat('<div>', $n) . '"'];
        yield 'CDATA in svg' => ['<svg><![CDATA[><a title="]]>' . str_repeat('<g>', $n) . '"'];
        yield 'script hiding its end tag' => [str_repeat('<div><script><!--<script></script></div>--></script>', 1000)];
        yield 'ruby text without ruby nests' => [str_repeat('<rt>', $n)];
        yield 'end tag beyond the walk closes nothing' => [str_repeat('<div>' . str_repeat('<x>', 70) . '</div>', 10)];
        yield 'one over' => [str_repeat('<b>', self::LIMIT + 1) . 'x'];
    }

    #[DataProvider('refused')]
    public function testRefused(string $html): void
    {
        self::assertTrue(NestingEstimator::exceeds($html, self::LIMIT));
        self::assertSame('', (new DomAllowlistSanitizer())->sanitize($html));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function accepted(): iterable
    {
        yield 'many comments are not rescanned' => [str_repeat('<!--a-->', 125000) . '<p>x</p>'];
        yield 'at the limit' => [str_repeat('<b>', self::LIMIT) . 'x'];
        yield 'unclosed links' => [str_repeat('<a href="/x">l ', 600)];
        yield 'unclosed headings' => [str_repeat('<h2>h ', 600)];
        yield 'comments holding a tag' => [str_repeat('<!-- <div> -->', 600) . '<p>x</p>'];
        yield 'less-than in text' => [str_repeat('a<b ', 600) . 'x'];
        yield 'less-than and digits' => [str_repeat('1 < 2 ', 600)];
        yield 'tags in attribute values' => [str_repeat('<a title="<b>" href="/x">l</a>', 600)];
        yield 'single quotes' => [str_repeat("<a title='<b>'>l</a>", 600)];
        yield 'unclosed span inside a block' => [str_repeat('<div><span>a</div>', 2000)];
        yield 'table without end tags' => ['<table>' . str_repeat('<tr><td>a<td>b', 3000) . '</table>'];
        yield 'list without end tags' => ['<ul>' . str_repeat('<li>a', 3000) . '</ul>'];
        yield 'paragraphs without end tags' => [str_repeat('<p>a', 5000)];
        yield 'closed sections' => [str_repeat('<section><h2>a</h2><p>b <a href="/x">c</a></p></section>', 3000)];
        yield 'line breaks and images' => [str_repeat('<br><img src="/a.png">', 3000)];
        yield 'style with tags' => ['<style>' . str_repeat('<div>', 600) . '</style>'];
        yield 'textarea with tags' => ['<textarea>' . str_repeat('<div>', 600) . '</textarea>'];
        yield 'small icons' => [str_repeat('<svg><path d="M0"/><circle/></svg>', 600)];
        yield 'text ends inside a tag' => ['<p>a</p>' . str_repeat('<!x>', 600) . '<div title="'];
    }

    #[DataProvider('accepted')]
    public function testAccepted(string $html): void
    {
        self::assertFalse(NestingEstimator::exceeds($html, self::LIMIT));
    }

    public function testStopsAtTheFirstValueAboveTheLimit(): void
    {
        self::assertSame(4, NestingEstimator::peak(str_repeat('<div>', 100), 3));
        self::assertSame(100, NestingEstimator::peak(str_repeat('<div>', 100)));
        self::assertSame(PHP_INT_MAX, NestingEstimator::peak('<svg><![CDATA[x]]>'));
    }

    /**
     * Real nesting stays within six times the estimate on random markup, as the class documents.
     */
    #[RequiresPhp('>=8.4')]
    public function testEstimateBoundsTheRealParser(): void
    {
        $atoms = [
            '<div>', '</div>', '<span>', '</span>', '<b>', '</b>', '<i a="1">', '</i>', '<a href="x">', '</a>', '<p>', '</p>', '<table>', '</table>',
            '<tr>', '<td>', '</td>', '<caption>', '<ul>', '</ul>', '<li>', '<h1>', '<h2>', '</h1>', '</h2>', '<section>', '</section>', '<object>',
            '</object>', '<select>', '</select>', '<option>', '<input>', '<br>', '<template>', '</template>', '<svg>', '</svg>', '<math>', '</math>',
            '<g/>', '<x/>', '<div/>', '<foreignObject>', '<title>', '</title>', '<style>', '</style>', '<textarea>', '</textarea>', '<script>',
            '</script>', '<xmp>', '</xmp>', '<noscript>', '</noscript>', '<!--', '-->', '--!>', '<!-->', '<!x>', '<?y>', '</ z>', '<![CDATA[', ']]>',
            '<div title="', '<a x=y">', '"', "'", '>', '<', 'text', '<button>', '</button>', '<marquee>', '</marquee>', '<dl>', '<dd>', '<ruby>', '<rt>',
            '<nobr>', '</nobr>', '<code>', '</code>', '<font>', '<plaintext>',
        ];
        mt_srand(20261106);
        for ($round = 0; $round < 4000; ++$round) {
            $html = '';
            for ($i = mt_rand(1, 120); $i > 0; --$i) {
                $atom = $atoms[array_rand($atoms)];
                $html .= str_repeat($atom, mt_rand(0, 3) === 0 ? mt_rand(2, 20) : 1);
            }
            $estimate = NestingEstimator::peak($html);
            if ($estimate === PHP_INT_MAX) {
                continue;
            }
            self::assertLessThanOrEqual($estimate * 6 + 8, self::realDepth($html), 'Estimate ' . $estimate . ' for: ' . $html);
        }
    }

    private static function realDepth(string $html): int
    {
        $document = HTMLDocument::createEmpty();
        $root     = $document->createElement('div');
        $document->appendChild($root);
        $root->innerHTML = $html;

        return self::depth($root);
    }

    private static function depth(Node $node): int
    {
        $max = 0;
        foreach ($node->childNodes as $child) {
            if ($child instanceof Element) {
                $inner = $child->localName === 'template' ? 0 : self::depth($child);
                $max   = max($max, 1 + $inner);
            }
        }

        return $max;
    }
}
