<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\Sanitizer;

use Nowo\Ckeditor5EditorBundle\Security\Ckeditor5HtmlSanitizerInterface;
use Nowo\LegalKitBundle\Sanitizer\DomAllowlistSanitizer;
use Nowo\LegalKitBundle\Sanitizer\UrlPolicy;
use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * XSS corpus (spec 155 "Security"): every vector fails closed.
 */
final class DomAllowlistSanitizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function corpus(): iterable
    {
        yield 'script' => ['<p>a</p><script>alert(1)</script>', '<p>a</p>'];
        yield 'script uppercase' => ['<SCRIPT src=//x></SCRIPT><p>b</p>', '<p>b</p>'];
        yield 'event handler' => ['<p onclick="x()" onMouseOver=y>c</p>', '<p>c</p>'];
        yield 'img onerror' => ['<img src="/a.png" onerror="alert(1)" alt="a">', '<img src="/a.png" alt="a">'];
        yield 'javascript href' => ['<a href="javascript:alert(1)">x</a>', '<a>x</a>'];
        yield 'javascript entities' => ['<a href="jav&#x09;ascript:alert(1)">x</a>', '<a>x</a>'];
        yield 'javascript whitespace' => ['<a href="  JaVaScRiPt:alert(1)">x</a>', '<a>x</a>'];
        yield 'javascript newline' => ["<a href=\"java\nscript:alert(1)\">x</a>", '<a>x</a>'];
        yield 'vbscript' => ['<a href="vbscript:msgbox(1)">x</a>', '<a>x</a>'];
        yield 'data href' => ['<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>', '<a>x</a>'];
        yield 'data image' => ['<img src="data:image/svg+xml;base64,PHN2Zz4=" alt="i">', '<img alt="i">'];
        yield 'http image' => ['<img src="http://example.com/a.png">', '<img>'];
        yield 'https image' => ['<img src="https://example.com/a.png" width="10" height="x">', '<img src="https://example.com/a.png" width="10">'];
        yield 'iframe srcdoc' => ['<iframe srcdoc="<script>alert(1)</script>"></iframe><p>d</p>', '<p>d</p>'];
        yield 'svg' => ['<svg onload="alert(1)"><circle r="1"/></svg><p>e</p>', '<p>e</p>'];
        yield 'math' => ['<math><mtext><script>x</script></mtext></math>f', 'f'];
        yield 'style element' => ['<style>body{display:none}</style><p>g</p>', '<p>g</p>'];
        yield 'style attribute url' => ['<p style="background:url(javascript:alert(1))">h</p>', '<p>h</p>'];
        yield 'noscript nested' => ['<noscript><p title="</noscript><img src=x onerror=alert(1)>"></p></noscript><p>i</p>', '<p>i</p>'];
        yield 'form controls' => ['<form action="/x"><input name="a"><button>b</button></form><p>j</p>', '<p>j</p>'];
        yield 'object embed' => ['<object data="x.swf"></object><embed src="x.swf"><p>k</p>', '<p>k</p>'];
        yield 'comment' => ['<p>l<!-- <script>x</script> --></p>', '<p>l</p>'];
        yield 'unknown element unwrapped' => ['<marquee><b>m</b></marquee>', '<b>m</b>'];
        yield 'nested unknown' => ['<custom-el><font color="red"><em>n</em></font></custom-el>', '<em>n</em>'];
        yield 'target blank' => ['<a href="https://example.com" target="_blank" rel="opener">o</a>', '<a href="https://example.com" target="_blank" rel="noopener noreferrer">o</a>'];
        yield 'bad target' => ['<a href="/x" target="frame1">p</a>', '<a href="/x">p</a>'];
        yield 'rel filtered' => ['<a href="/x" rel="nofollow evil">q</a>', '<a href="/x" rel="nofollow">q</a>'];
        yield 'class filtered' => ['<p class="lead">r</p><p class="x&quot;onclick">s</p>', '<p class="lead">r</p><p>s</p>'];
        yield 'table' => ['<figure class="table"><table><thead><tr><th scope="col" colspan="2">t</th></tr></thead><tbody><tr><td rowspan="x">u</td></tr></tbody></table></figure>', '<figure class="table"><table><thead><tr><th scope="col" colspan="2">t</th></tr></thead><tbody><tr><td>u</td></tr></tbody></table></figure>'];
        yield 'q6 additions' => ['<p><code>c</code><mark>m</mark>x<sup>2</sup><sub>i</sub></p><pre>p</pre>', '<p><code>c</code><mark>m</mark>x<sup>2</sup><sub>i</sub></p><pre>p</pre>'];
        yield 'links kept' => ['<a href="mailto:a@b.c">a</a><a href="tel:+341">b</a><a href="#s">c</a><a href="./x">d</a><a href="page.html?x=1">e</a><a href="?q=1">f</a><a href="../up">g</a>', '<a href="mailto:a@b.c">a</a><a href="tel:+341">b</a><a href="#s">c</a><a href="./x">d</a><a href="page.html?x=1">e</a><a href="?q=1">f</a><a href="../up">g</a>'];
        yield 'empty href' => ['<a href="  ">x</a>', '<a>x</a>'];
        yield 'unknown scheme' => ['<a href="ftp://x">x</a><a href="weird:x">y</a>', '<a>x</a><a>y</a>'];
        yield 'protocol relative' => ['<a href="//example.com/x">x</a>', '<a href="//example.com/x">x</a>'];
        yield 'http without host' => ['<a href="http:/x">x</a>', '<a>x</a>'];
        yield 'consent opener' => ['<a href="#" data-nowo-open-consent>x</a><a data-nowo-open-consent="evil()">y</a>', '<a href="#" data-nowo-open-consent="">x</a><a>y</a>'];
        yield 'image anchor' => ['<img src="#x">', '<img>'];
        yield 'loading' => ['<img src="/a.png" loading="lazy"><img src="/b.png" loading="never">', '<img src="/a.png" loading="lazy"><img src="/b.png">'];
        yield 'text escaped' => ['5 &lt; 6 &amp; "q"', '5 &lt; 6 &amp; "q"'];
        yield 'empty' => ['   ', ''];
        yield 'backslash host' => ['<a href="/\\evil.tld">x</a><img src="\\\\evil.tld/p.png">', '<a>x</a><img>'];
        yield 'unbalanced wrapper close' => ['<p>a</p></div><p>b</p><script>x</script>', '<p>a</p><p>b</p>'];
        yield 'unicode' => ['<p>Política — “ñ” €</p>', '<p>Política — “ñ” €</p>'];
    }

    #[DataProvider('corpus')]
    public function testCorpus(string $input, string $expected): void
    {
        self::assertSame($expected, (new DomAllowlistSanitizer())->sanitize($input));
    }

    public function testExtraElementsAreMergedButDangerousOnesStayRemoved(): void
    {
        $sanitizer = new DomAllowlistSanitizer(new UrlPolicy(), [
            'abbr'   => ['title', 'onclick', 'style'],
            'p'      => ['lang', 'id', 'srcset'],
            'script' => ['src'],
        ]);

        self::assertSame(
            '<abbr title="GDPR">GDPR</abbr><p lang="es">a</p>',
            $sanitizer->sanitize('<abbr title="GDPR" onclick="x" style="a">GDPR</abbr><p lang="es" id="x" srcset="/a.png">a</p><script src="/x.js"></script>'),
        );
    }

    public function testFirstPassRunsBeforeTheAllowlist(): void
    {
        $firstPass = new class implements Ckeditor5HtmlSanitizerInterface {
            public function sanitize(string $html): string
            {
                return str_replace('secret', '[removed]', $html);
            }
        };

        self::assertSame('<p>[removed]</p>', (new DomAllowlistSanitizer(new UrlPolicy(), [], $firstPass))->sanitize('<p>secret</p>'));
    }

    public function testOutboundUrlGuard(): void
    {
        $sanitizer = new DomAllowlistSanitizer(new UrlPolicy(new OutboundUrlGuard(resolveDns: false)));

        self::assertSame('<a>a</a><a>b</a><a>c</a><a href="https://example.com/">d</a><a href="/rel">e</a>', $sanitizer->sanitize(
            '<a href="http://127.0.0.1/">a</a><a href="https://192.168.1.2/x">b</a><a href="//169.254.169.254/latest">c</a><a href="https://example.com/">d</a><a href="/rel">e</a>',
        ));
    }
}
