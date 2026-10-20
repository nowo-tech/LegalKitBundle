<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Integration;

use Nowo\LegalKitBundle\Document\ResolvedLegalDocument;
use Nowo\LegalKitBundle\Tests\Support\KernelTestCase;
use Nowo\LegalKitBundle\Tests\Support\PageFixtures;
use Nowo\LegalKitBundle\Tests\Support\TestKernel;
use Nowo\LegalKitBundle\Token\CookieInventoryTokenResolver;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\LocaleSwitcher;
use Twig\Environment;
use Twig\Markup;

use function preg_match;

/**
 * Bundle templates as the host renders them: render-time sanitising of the legal body (REQ-PBK-009) and the
 * translated category label of the cookie inventory (with its override block).
 */
final class TemplateOutputTest extends KernelTestCase
{
    private const array COOKIES = [
        ['name' => 'PHPSESSID', 'category' => 'required', 'duration' => 'Session', 'purpose' => 'Session', 'provider' => ''],
        ['name' => '_ga', 'category' => 'analytics', 'duration' => '2 years', 'purpose' => 'Statistics', 'provider' => 'Google'],
        ['name' => 'custom', 'category' => 'host_only', 'duration' => '1 day', 'purpose' => 'Other', 'provider' => ''],
        ['name' => 'blank', 'category' => '', 'duration' => '', 'purpose' => '', 'provider' => ''],
    ];

    public function testStoredMarkupIsSanitisedAtRenderEvenWhenItBypassedTheSavePass(): void
    {
        $twig = $this->twig();
        $body = '<h2 class="lead">Allowed</h2><p>Text <strong>bold</strong> <a href="https://example.com/x" target="_blank">link</a></p>'
            . '<script>alert(1)</script><img src="/a.png" alt="a" onerror="alert(2)"><p onclick="alert(3)" style="position:fixed">x</p>'
            . '<a href="javascript:alert(4)">j</a><a href="java&colon;script:alert(5)">k</a><style>body{display:none}</style>'
            . '<svg onload="alert(6)"></svg><iframe srcdoc="<script>alert(7)</script>"></iframe>';
        $context             = PageFixtures::publicPage(true);
        $context['document'] = new ResolvedLegalDocument('privacy', 'en', 'Privacy <policy>', $body, ResolvedLegalDocument::SOURCE_STORED, 'en');

        $page = $twig->render('@NowoLegalKitBundle/public/page.html.twig', $context);
        self::assertSame(1, preg_match('#<article class="nowo-legal-doc.*?</article>#s', $page, $article));
        $html = $article[0];

        self::assertStringContainsString('<h2 class="lead">Allowed</h2>', $html);
        self::assertStringContainsString('<strong>bold</strong>', $html);
        self::assertStringContainsString('<a href="https://example.com/x" target="_blank" rel="noopener noreferrer">link</a>', $html);
        self::assertStringContainsString('<img src="/a.png" alt="a">', $html);
        foreach (['<script', 'alert(', 'onerror', 'onclick', 'style=', '<style', 'javascript:', '<svg', '<iframe', 'srcdoc'] as $needle) {
            self::assertStringNotContainsString($needle, $html, $needle . ' reached the page');
        }
        self::assertStringContainsString('<title>Privacy &lt;policy&gt;</title>', $page, 'Title auto-escaped');
    }

    public function testSanitizeFilterHandlesNonStrings(): void
    {
        $twig = $this->twig();

        self::assertSame('<p>a</p>', $twig->createTemplate('{{ v|nowo_legal_kit_sanitize_html }}')->render(['v' => new Markup('<p onclick="x()">a</p>', 'UTF-8')]));
        self::assertSame('', $twig->createTemplate('{{ v|nowo_legal_kit_sanitize_html }}')->render(['v' => null]));
        self::assertSame('', $twig->createTemplate('{{ v|nowo_legal_kit_sanitize_html }}')->render(['v' => new stdClass()]));
        self::assertSame('42', $twig->createTemplate('{{ v|nowo_legal_kit_sanitize_html }}')->render(['v' => 42]));
    }

    public function testCookieCategoryIsATranslatedLabel(): void
    {
        $twig = $this->twig();

        $html = $twig->render(CookieInventoryTokenResolver::TEMPLATE, ['cookies' => self::COOKIES]);
        self::assertStringContainsString('<td data-nowo-legal-cookie-category="required">Strictly necessary</td>', $html);
        self::assertStringContainsString('<td data-nowo-legal-cookie-category="analytics">Analytics</td>', $html);
        self::assertStringContainsString('<td data-nowo-legal-cookie-category="host_only">host_only</td>', $html, 'Unknown keys print as they are');
        self::assertStringContainsString('<td data-nowo-legal-cookie-category=""></td>', $html);
        self::assertStringNotContainsString('cookies.category.', $html);

        $this->switchLocale('es');
        $html = $twig->render(CookieInventoryTokenResolver::TEMPLATE, ['cookies' => self::COOKIES]);
        self::assertStringContainsString('<td data-nowo-legal-cookie-category="required">Estrictamente necesarias</td>', $html);
        self::assertStringContainsString('<td data-nowo-legal-cookie-category="analytics">Analítica</td>', $html);
    }

    public function testHostsCanOverrideOnlyTheCategoryLabel(): void
    {
        $twig     = $this->twig();
        $override = $twig->createTemplate(
            "{% extends '@NowoLegalKitBundle/_cookie_inventory.html.twig' %}"
            . '{% block nowo_legal_kit_cookie_category_label %}[{{ cookie.category }}: {{ category_label }}]{% endblock %}',
        );

        $html = $override->render(['cookies' => self::COOKIES]);
        self::assertStringContainsString('[required: Strictly necessary]', $html);
        self::assertStringContainsString('[host_only: host_only]', $html);
        self::assertStringContainsString('<code>PHPSESSID</code>', $html, 'The rest of the table is the bundle markup');
    }

    private ?TestKernel $kernel = null;

    private function twig(): Environment
    {
        $this->kernel = $this->bootKernel();
        $container    = $this->testContainer($this->kernel);
        $stack        = $container->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        $stack->push(Request::create('/legal/privacy'));
        $twig = $container->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $twig;
    }

    private function switchLocale(string $locale): void
    {
        self::assertInstanceOf(TestKernel::class, $this->kernel);
        $switcher = $this->testContainer($this->kernel)->get('translation.locale_switcher');
        self::assertInstanceOf(LocaleSwitcher::class, $switcher);
        $switcher->setLocale($locale);
    }
}
