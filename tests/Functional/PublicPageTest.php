<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Functional;

use Nowo\LegalKitBundle\Tests\Support\KernelTestCase;

/**
 * Public legal pages: seed and stored text, default-locale URL styles, fallback locale, cache headers.
 */
final class PublicPageTest extends KernelTestCase
{
    public function testDefaultLocaleSeedOnTheBarePath(): void
    {
        $client = $this->client($this->bootKernel());
        $client->request('GET', '/legal/privacy');

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();
        self::assertStringContainsString('<h1>Privacy policy</h1>', $html);
        self::assertStringContainsString('data-nowo-legal-source="seed"', $html);
        self::assertStringContainsString('lang="en"', $html);
        self::assertStringContainsString('privacy@example.com', $html);
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('href="/legal/notice"', $html, 'Cross navigation to the other pages');
        self::assertStringNotContainsString('href="/legal/privacy"', $html, 'The current page is not in the cross navigation');
        self::assertSame('en', $client->getRequest()->attributes->get('_content_locale'));
    }

    public function testPrefixedDefaultLocaleRedirectsToTheBarePathKeepingTheQuery(): void
    {
        $client = $this->client($this->bootKernel());
        $client->request('GET', '/en/legal/terms?ref=footer');

        self::assertSame(301, $client->getResponse()->getStatusCode());
        self::assertSame('/legal/terms?ref=footer', $client->getResponse()->headers->get('Location'));
    }

    public function testOtherLocaleUsesItsOwnSeed(): void
    {
        $client = $this->client($this->bootKernel());
        $client->request('GET', '/es/legal/notice');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<h1>Aviso legal</h1>', $html);
        self::assertStringContainsString('lang="es"', $html);
        self::assertStringContainsString('Nombre legal del operador', $html);
        self::assertStringContainsString('href="/es/legal/privacy"', $html);
    }

    public function testDisabledLocaleIsNotFound(): void
    {
        $client = $this->client($this->bootKernel());
        $client->request('GET', '/fr/legal/notice');

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testStoredDocumentIsSanitisedAndTokensResolved(): void
    {
        $kernel = $this->bootKernel(['brand_name' => 'Acme <Cloud>']);
        $this->store($kernel, 'terms', 'es', 'Condiciones', '<h1>Condiciones de %brand%</h1><script>alert(1)</script><p onclick="x()">Hola <a href="javascript:alert(1)">x</a></p><p>%unknown_token%</p>');
        $client = $this->client($kernel);
        $client->request('GET', '/es/legal/terms');

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();
        self::assertStringContainsString('<h1>Condiciones de Acme &lt;Cloud&gt;</h1>', $html);
        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringNotContainsString('onclick', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringContainsString('%unknown_token%', $html);
        self::assertStringContainsString('data-nowo-legal-source="stored"', $html);
        self::assertNotNull($response->headers->get('Last-Modified'));
        self::assertStringContainsString('<title>Condiciones</title>', $html);
    }

    public function testStoredDocumentWithoutTextFallsBackToTheSeed(): void
    {
        $kernel = $this->bootKernel();
        $this->store($kernel, 'cookies', 'en', 'Empty', '<p>&nbsp;</p>');
        $client = $this->client($kernel);
        $client->request('GET', '/legal/cookies');

        self::assertStringContainsString('data-nowo-legal-source="seed"', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('data-nowo-open-consent', (string) $client->getResponse()->getContent());
    }

    public function testPrefixedStyleRedirectsTheBarePath(): void
    {
        $client = $this->client($this->bootKernel(['routing' => ['default_locale_style' => 'prefixed']]));
        $client->request('GET', '/legal/privacy?x=1');
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('/en/legal/privacy?x=1', $client->getResponse()->headers->get('Location'));

        $client->request('GET', '/en/legal/privacy');
        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testSharedCacheIsOptIn(): void
    {
        $client = $this->client($this->bootKernel(['public' => ['cache' => ['max_age' => 60, 'shared_max_age' => 300]]]));
        $client->request('GET', '/legal/notice');

        $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('s-maxage=300', $cacheControl);
        self::assertStringContainsString('max-age=60', $cacheControl);
    }

    public function testFallbackLocaleServesTheFallbackSeed(): void
    {
        $client = $this->client($this->bootKernel(['locales' => ['en', 'ca'], 'seed' => ['fallback_locale' => 'en']]));
        $client->request('GET', '/ca/legal/privacy');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-nowo-legal-source="fallback"', $html);
        self::assertStringContainsString('lang="en"', $html);
        self::assertSame('en', $client->getRequest()->attributes->get('_content_locale'));
    }

    public function testPageWithoutTextIsNotFound(): void
    {
        $client = $this->client($this->bootKernel(['locales' => ['en', 'ca']]));
        $client->request('GET', '/ca/legal/privacy');

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testCustomPathPrefixAndExtraPage(): void
    {
        $client = $this->client($this->bootKernel([
            'routing' => ['path_prefix' => '/info'],
            'pages'   => [
                'notice'  => ['path' => 'imprint'],
                'privacy' => [],
                'terms'   => ['enabled' => false],
                'cookies' => ['nav' => false],
            ],
        ]));
        $client->request('GET', '/info/imprint');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('href="/info/privacy"', $html);
        self::assertStringNotContainsString('/info/terms', $html);
        self::assertStringNotContainsString('href="/info/cookies"', $html);

        $client->request('GET', '/info/terms');
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }
}
