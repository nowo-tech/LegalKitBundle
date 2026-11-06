<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\Sanitizer;

use Nowo\LegalKitBundle\Sanitizer\DomAllowlistSanitizer;
use Nowo\LegalKitBundle\Sanitizer\UrlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function substr_count;

/**
 * `sanitizer.heading_anchors`: the only way an `id` reaches sanitised output, on both parsers.
 */
final class HeadingAnchorsTest extends TestCase
{
    private const array ANCHORS = ['time-tracking', 'chat', 'mfa', 'uptime'];

    /**
     * @return iterable<string, array{bool}>
     */
    public static function parsers(): iterable
    {
        yield 'HTML5 parser where PHP has it' => [true];
        yield 'libxml parser' => [false];
    }

    /**
     * @param list<string> $anchors
     * @param array<string, list<string>> $extra
     */
    private function sanitizer(bool $html5, array $anchors = self::ANCHORS, array $extra = []): DomAllowlistSanitizer
    {
        return new DomAllowlistSanitizer(new UrlPolicy(), $extra, null, $html5, $anchors);
    }

    #[DataProvider('parsers')]
    public function testKnownAnchorBecomesTheFirstAttribute(bool $html5): void
    {
        self::assertSame(
            '<h3 id="time-tracking" class="legal-anchor--time-tracking">Time</h3><p>x</p>',
            $this->sanitizer($html5)->sanitize('<h3 class="legal-anchor--time-tracking">Time</h3><p>x</p>'),
        );
        self::assertSame(
            '<section id="chat" class="x legal-anchor--chat">B</section>',
            $this->sanitizer($html5)->sanitize('<section class="x legal-anchor--chat">B</section>'),
        );
    }

    #[DataProvider('parsers')]
    public function testIdGoesFirstWhateverOtherAttributesTheElementKeeps(bool $html5): void
    {
        self::assertSame(
            '<h2 id="chat" title="t" class="legal-anchor--chat">A</h2>',
            $this->sanitizer($html5, extra: ['h2' => ['title']])->sanitize('<h2 title="t" class="legal-anchor--chat">A</h2>'),
        );
    }

    #[DataProvider('parsers')]
    public function testInputIdsAreDroppedAndUnlistedNamesIgnored(bool $html5): void
    {
        $html = $this->sanitizer($html5)->sanitize('<h2 id="location" class="legal-anchor--location">A</h2><h2 id="chat">B</h2><h2 class="legal-anchor--">C</h2>');

        self::assertSame('<h2 class="legal-anchor--location">A</h2><h2>B</h2><h2 class="legal-anchor--">C</h2>', $html);
    }

    #[DataProvider('parsers')]
    public function testAnInputIdNeverReplacesTheAnchor(bool $html5): void
    {
        self::assertSame(
            '<h2 id="chat" class="legal-anchor--chat">A</h2>',
            $this->sanitizer($html5)->sanitize('<h2 id="evil" class="legal-anchor--chat">A</h2>'),
        );
    }

    #[DataProvider('parsers')]
    public function testFirstOccurrenceInDocumentOrderWins(bool $html5): void
    {
        $html = $this->sanitizer($html5)->sanitize('<section class="legal-anchor--mfa"><h3 class="legal-anchor--mfa legal-anchor--uptime">A</h3></section><h3 class="legal-anchor--mfa">B</h3>');

        self::assertSame(
            '<section id="mfa" class="legal-anchor--mfa"><h3 id="uptime" class="legal-anchor--mfa legal-anchor--uptime">A</h3></section><h3 class="legal-anchor--mfa">B</h3>',
            $html,
        );
        self::assertSame(1, substr_count($html, 'id="mfa"'));
    }

    #[DataProvider('parsers')]
    public function testOnlyHeadingsAndSectionsCarryAnAnchor(bool $html5): void
    {
        foreach (['div', 'p', 'span', 'a', 'img'] as $tag) {
            self::assertStringNotContainsString(' id=', $this->sanitizer($html5)->sanitize('<' . $tag . ' class="legal-anchor--chat">x</' . $tag . '>'), $tag);
        }
        foreach (['h1', 'h2', 'h3', 'h4', 'section'] as $tag) {
            self::assertStringContainsString('<' . $tag . ' id="chat"', $this->sanitizer($html5)->sanitize('<' . $tag . ' class="legal-anchor--chat">x</' . $tag . '>'), $tag);
        }
    }

    #[DataProvider('parsers')]
    public function testAnchorsSurviveInsideUnwrappedElements(bool $html5): void
    {
        self::assertSame(
            '<h2 id="chat" class="legal-anchor--chat">A</h2>',
            $this->sanitizer($html5)->sanitize('<custom-el><h2 class="legal-anchor--chat">A</h2></custom-el>'),
        );
    }

    /** Save, seed materialisation and render each sanitise: the text must not change between passes. */
    #[DataProvider('parsers')]
    public function testIdempotent(bool $html5): void
    {
        $sanitizer = $this->sanitizer($html5);
        $once      = $sanitizer->sanitize('<h2 class="legal-anchor--chat">A</h2><h2 class="legal-anchor--chat">B</h2>');

        self::assertSame($once, $sanitizer->sanitize($once));
    }

    #[DataProvider('parsers')]
    public function testEmptyListKeepsTheDefaultOutput(bool $html5): void
    {
        $default = new DomAllowlistSanitizer(html5: $html5);
        $html    = '<h2 id="chat" class="legal-anchor--chat">A</h2><section class="legal-anchor--mfa">B</section>';

        self::assertSame('<h2 class="legal-anchor--chat">A</h2><section class="legal-anchor--mfa">B</section>', $default->sanitize($html));
        self::assertSame($default->sanitize($html), $this->sanitizer($html5, [])->sanitize($html));
    }
}
