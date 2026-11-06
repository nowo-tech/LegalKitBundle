<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Functional;

use Nowo\LegalKitBundle\Tests\Support\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

use function dirname;
use function file_get_contents;
use function preg_match;
use function preg_match_all;

/**
 * Public page through a real kernel (TwigBundle registered): heading anchors, `public.prose_class`, typography tokens.
 */
final class DocumentPresentationTest extends KernelTestCase
{
    private const string BODY = '<h2 id="evil" class="legal-anchor--retention">Retention</h2><h2 class="legal-anchor--rights">Rights</h2><h2 class="legal-anchor--retention">Again</h2>';

    /**
     * @param array<string, mixed> $config
     */
    private function page(array $config): string
    {
        $kernel = $this->bootKernel($config);
        $this->store($kernel, 'privacy', 'en', 'Privacy', self::BODY);
        $client = $this->client($kernel);
        $client->request('GET', '/legal/privacy');
        self::assertSame(200, $client->getResponse()->getStatusCode());

        return (string) $client->getResponse()->getContent();
    }

    public function testNoIdAndNoExtraClassByDefault(): void
    {
        $html = $this->page([]);

        self::assertStringContainsString('<h2 class="legal-anchor--retention">Retention</h2>', $html);
        self::assertStringNotContainsString('id="retention"', $html);
        self::assertStringNotContainsString('id="evil"', $html);
        self::assertStringNotContainsString('--typography', $html);
        self::assertSame(1, preg_match('/<article class="nowo-legal-doc ([^"]*)"/', $html, $default));

        // The class attribute is exactly "nowo-legal-doc <section slot>".
        $kernel = $this->bootKernel();
        $stack  = $this->testContainer($kernel)->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        $stack->push(Request::create('/legal/privacy'));
        $twig = $this->testContainer($kernel)->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $slotOnly = $twig->createTemplate("{{ nowo_ui_class('section', 'NowoLegalKitBundle') }}")->render();
        self::assertSame($slotOnly, $default[1]);
    }

    public function testConfiguredAnchorsReachThePublicPage(): void
    {
        $html = $this->page(['sanitizer' => ['heading_anchors' => ['retention']]]);

        self::assertStringContainsString('<h2 id="retention" class="legal-anchor--retention">Retention</h2>', $html);
        self::assertStringContainsString('<h2 class="legal-anchor--rights">Rights</h2>', $html, 'Not in the list');
        self::assertStringContainsString('<h2 class="legal-anchor--retention">Again</h2>', $html, 'First occurrence only');
        self::assertStringNotContainsString('id="evil"', $html);
    }

    public function testProseClassReachesTheArticle(): void
    {
        $html = $this->page(['public' => ['prose_class' => 'nowo-legal-doc--typography prose']]);

        self::assertSame(1, preg_match('/<article class="nowo-legal-doc nowo-legal-doc--typography prose /', $html));
    }

    public function testTypographyTokensOnlyActUnderTheOptInClass(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Resources/public/css/nowo-legal-kit.css');

        foreach (['h1-size', 'h2-size', 'list-indent', 'gap'] as $token) {
            self::assertStringContainsString('var(--nowo-legal-doc-' . $token . ', ', $css, 'Token with a fallback: ' . $token);
        }
        // Without the class a heading or list rule would change the page of a host that set nothing.
        self::assertSame(0, preg_match('/(^|[,}\s])\.nowo-legal-doc\s+(h[1-6]|ul|ol|p|li)\b/', $css));
        self::assertGreaterThan(0, preg_match_all('/\.nowo-legal-doc--typography (h1|h2|ul|ol)/', $css));
        self::assertSame(0, preg_match('/\.nowo-legal-acceptance__[a-z-]+\s*[,{]/', (string) preg_replace('/\.nowo-legal-acceptance--compact \.nowo-legal-acceptance__[a-z-]+/', '', $css)), 'Compact rules are scoped');
    }
}
