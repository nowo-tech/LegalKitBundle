<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Functional;

use Nowo\LegalKitBundle\Tests\Support\KernelTestCase;
use Nowo\LegalKitBundle\Tests\Support\TestBrandProvider;
use Nowo\LegalKitBundle\Tests\Support\TestKernel;

/**
 * MUX-150: the optional header of the public legal pages (brand linked home, sign-in link).
 */
final class PublicHeaderTest extends KernelTestCase
{
    public function testOffByDefaultTheMarkupIsUnchanged(): void
    {
        $client = $this->client($this->bootKernel(['brand_name' => 'Acme']));
        $client->request('GET', '/legal/privacy');

        $html = (string) $client->getResponse()->getContent();
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('nowo-legal-header', $html);
        self::assertStringNotContainsString('nowo-legal-kit-header', $html);
    }

    public function testBrandNameLinkedHomeAndSignInRoute(): void
    {
        $kernel = $this->bootKernel([
            'brand_name' => 'Acme <Cloud> & "Co"',
            'public'     => ['header' => ['enabled' => true, 'home' => 'app_home', 'sign_in' => '/login?next=/legal']],
        ], ['app_routes']);
        $client = $this->client($kernel);
        $client->request('GET', '/es/legal/privacy');

        $html = (string) $client->getResponse()->getContent();
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('data-testid="nowo-legal-kit-header"', $html);
        self::assertStringContainsString('<a class="nowo-legal-header__brand" href="/app" aria-label="Acme &lt;Cloud&gt; &amp; &quot;Co&quot; — Inicio">', $html);
        self::assertStringContainsString('<span class="nowo-legal-header__name">Acme &lt;Cloud&gt; &amp; &quot;Co&quot;</span>', $html);
        self::assertStringContainsString('href="/login?next=/legal"', $html);
        self::assertStringContainsString('>Iniciar sesión</a>', $html);
        self::assertStringNotContainsString('<Cloud>', $html);
        self::assertStringNotContainsString('<img', $html);
        // Before the article, inside the layout.
        self::assertLessThan(strpos($html, 'nowo-legal-doc'), strpos($html, 'nowo-legal-header'));
    }

    public function testShorthandTrueUsesTheDefaults(): void
    {
        $client = $this->client($this->bootKernel(['brand_name' => '', 'public' => ['header' => true]]));
        $client->request('GET', '/legal/terms');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<a class="nowo-legal-header__brand" href="/" aria-label="Home — Home">', $html);
        self::assertStringNotContainsString('nowo-legal-header__sign-in', $html, 'No sign-in target configured');
    }

    public function testTheProviderLogoIsAnEscapedImage(): void
    {
        $kernel                        = $this->bootKernel(['public' => ['header' => ['enabled' => true, 'sign_in' => 'app_login_missing']]], ['brand_provider']);
        $this->provider($kernel)->logo = '/brand/logo.png?v=1&x=2';
        $client                        = $this->client($kernel);
        $client->request('GET', '/legal/notice');

        $response = $client->getResponse();
        $html     = (string) $response->getContent();
        self::assertSame(200, $response->getStatusCode(), 'An unknown sign-in route never fails the page');
        self::assertStringContainsString('<img class="nowo-legal-header__logo" src="/brand/logo.png?v=1&amp;x=2" alt="Acme &lt;Cloud&gt; &amp; &quot;Co&quot;">', $html);
        self::assertStringNotContainsString('nowo-legal-header__name', $html);
        self::assertStringNotContainsString('nowo-legal-header__sign-in', $html);
        self::assertIsString($response->getEtag(), 'Still validated by the brand ETag');
    }

    public function testLogoOffPrintsTheProviderName(): void
    {
        $kernel                        = $this->bootKernel(['public' => ['header' => ['logo' => false]]], ['brand_provider']);
        $this->provider($kernel)->logo = '/brand/logo.png';
        $client                        = $this->client($kernel);
        $client->request('GET', '/legal/notice');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<span class="nowo-legal-header__name">Acme &lt;Cloud&gt; &amp; &quot;Co&quot;</span>', $html);
        self::assertStringNotContainsString('nowo-legal-header__logo', $html);
    }

    private function provider(TestKernel $kernel): TestBrandProvider
    {
        $provider = $this->testContainer($kernel)->get(TestBrandProvider::class);
        self::assertInstanceOf(TestBrandProvider::class, $provider);

        return $provider;
    }
}
