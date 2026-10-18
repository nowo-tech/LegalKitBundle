<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Integration;

use Nowo\LegalKitBundle\Seo\LegalSitemapProvider;
use Nowo\LegalKitBundle\Sanitizer\LegalHtmlSanitizerInterface;
use Nowo\LegalKitBundle\Tests\Support\KernelTestCase;
use Nowo\SeoKitBundle\Service\SeoRuntime;
use Symfony\Component\HttpFoundation\Request;

/**
 * Soft dependencies: CookieConsent (legal routes prepended), SeoKit (sitemap + head), CKEditor 5 (editor + first
 * sanitiser pass), OutboundUrlGuard (private-network links dropped).
 */
final class OptionalIntegrationsTest extends KernelTestCase
{
    public function testCookieConsentGetsTheLegalRoutes(): void
    {
        $kernel    = $this->bootKernel([], ['cookie_consent'], true, ['nowo_cookie_consent' => ['render_routes' => ['app_*']]]);
        $container = $kernel->getContainer();

        $disabled = $container->getParameter('nowo_cookie_consent.disabled_routes');
        self::assertIsArray($disabled);
        foreach (['legal_notice', 'legal_privacy_unlocalized', 'legal_cookies'] as $route) {
            self::assertContains($route, $disabled);
        }
        $render = $container->getParameter('nowo_cookie_consent.render_routes');
        self::assertIsArray($render);
        self::assertContains('legal_*', $render);
        self::assertContains('app_*', $render);
        self::assertTrue($container->getParameter('nowo_legal_kit.cookie_consent.installed'));
    }

    public function testCookieConsentRenderWhitelistIsNotCreated(): void
    {
        $render = $this->bootKernel([], ['cookie_consent'])->getContainer()->getParameter('nowo_cookie_consent.render_routes');

        self::assertSame([], $render);
    }

    public function testCookieConsentPrependCanBeDisabled(): void
    {
        $disabled = $this->bootKernel(['cookie_consent' => ['prepend_routes' => false]], ['cookie_consent'])->getContainer()->getParameter('nowo_cookie_consent.disabled_routes');

        self::assertIsArray($disabled);
        self::assertNotContains('legal_privacy', $disabled);
    }

    public function testCookiesPageRendersWithCookieConsent(): void
    {
        $client = $this->client($this->bootKernel([], ['cookie_consent']));
        $client->request('GET', '/legal/cookies');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('%cookie_inventory%', (string) $client->getResponse()->getContent());
    }

    public function testSeoKitSitemapAndHead(): void
    {
        $kernel    = $this->bootKernel([], ['seo_kit']);
        $container = $kernel->getContainer()->get('test.service_container');
        $provider  = $container->get(LegalSitemapProvider::class);
        self::assertInstanceOf(LegalSitemapProvider::class, $provider);

        $entries = $provider->getEntries(Request::create('/sitemap.xml'));
        self::assertCount(12, $entries);
        self::assertSame('http://localhost/legal/notice', $entries[0]['loc']);
        self::assertSame('http://localhost/es/legal/notice', $entries[1]['loc']);
        self::assertContains(['hreflang' => 'x-default', 'href' => 'http://localhost/legal/notice'], $entries[0]['alternates']);

        $client = $this->client($kernel);
        $client->request('GET', '/es/legal/privacy');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $runtime = $container->get(SeoRuntime::class);
        self::assertInstanceOf(SeoRuntime::class, $runtime);
        $overrides = $runtime->getOverrides();
        self::assertSame('Política de privacidad', $overrides['title'] ?? null);
        self::assertSame('http://localhost/es/legal/privacy', $overrides['canonical'] ?? null);
    }

    public function testSeoKitBridgesCanBeDisabled(): void
    {
        $container = $this->bootKernel(['seo' => ['sitemap' => false, 'head' => false]], ['seo_kit'])->getContainer()->get('test.service_container');

        self::assertFalse($container->has(LegalSitemapProvider::class));
    }

    public function testCkeditorIsTheDefaultEditor(): void
    {
        $kernel = $this->bootKernel([], ['ckeditor']);
        self::assertSame('ckeditor5', $kernel->getContainer()->getParameter('nowo_legal_kit.editor.type'));

        $client = $this->adminClient($kernel);
        $client->request('GET', '/admin/legal/privacy/en');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('ckeditor5', (string) $client->getResponse()->getContent());
    }

    public function testTextareaCanBeForcedWithCkeditorInstalled(): void
    {
        $kernel = $this->bootKernel(['editor' => ['type' => 'textarea']], ['ckeditor']);

        self::assertSame('textarea', $kernel->getContainer()->getParameter('nowo_legal_kit.editor.type'));
    }

    public function testOutboundUrlGuardDropsPrivateTargets(): void
    {
        $sanitizer = $this->bootKernel([], ['outbound_url_guard'])->getContainer()->get('test.service_container')->get(LegalHtmlSanitizerInterface::class);
        self::assertInstanceOf(LegalHtmlSanitizerInterface::class, $sanitizer);

        self::assertSame(
            '<p><a>meta</a> <a>lan</a> <a href="https://example.com/x">ok</a></p>',
            $sanitizer->sanitize('<p><a href="http://169.254.169.254/latest">meta</a> <a href="http://10.0.0.1/">lan</a> <a href="https://example.com/x">ok</a></p>'),
        );
    }
}
