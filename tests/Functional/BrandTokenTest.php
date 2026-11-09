<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Functional;

use Nowo\LegalKitBundle\Brand\LegalBrand;
use Nowo\LegalKitBundle\Tests\Support\KernelTestCase;
use Nowo\LegalKitBundle\Tests\Support\TestBrandProvider;
use Nowo\LegalKitBundle\Tests\Support\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Spec 220 S5: `%brand%` from a brand-contracts provider, `brand_name` as the fallback.
 */
final class BrandTokenTest extends KernelTestCase
{
    private const string BODY = '<h1>Condiciones de %brand%</h1><p>Hola</p>';

    public function testWithoutProviderTheOutputAndHeadersAreThoseOf15(): void
    {
        $kernel = $this->bootKernel(['brand_name' => 'Acme <Cloud>']);
        $this->store($kernel, 'terms', 'es', 'Condiciones %brand%', self::BODY);
        $client = $this->client($kernel);
        $client->request('GET', '/es/legal/terms');

        $response = $client->getResponse();
        $html     = (string) $response->getContent();
        self::assertStringContainsString('<h1>Condiciones de Acme &lt;Cloud&gt;</h1>', $html);
        self::assertNotNull($response->headers->get('Last-Modified'));
        self::assertNull($response->getEtag());
        self::assertNull($this->brand($kernel)->current(), 'brand-contracts installed but no provider service');
    }

    public function testTheProviderNameIsEscapedInTheBodyAndTheTitle(): void
    {
        $kernel = $this->bootKernel(['brand_name' => 'Configured'], ['brand_provider']);
        $this->store($kernel, 'terms', 'es', 'Condiciones %brand%', self::BODY);
        $client = $this->client($kernel);
        $client->request('GET', '/es/legal/terms');

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();
        // The sanitiser re-serialises the escaped text: quotes are plain characters in a text node.
        self::assertStringContainsString('<h1>Condiciones de Acme &lt;Cloud&gt; &amp; "Co"</h1>', $html);
        self::assertStringContainsString('<title>Condiciones Acme &lt;Cloud&gt; &amp; &quot;Co&quot;</title>', $html);
        self::assertStringNotContainsString('<Cloud>', $html);
        self::assertStringNotContainsString('Configured', $html);
    }

    public function testTheSeedUsesTheProviderName(): void
    {
        $kernel = $this->bootKernel([], ['brand_provider']);
        $client = $this->client($kernel);
        $client->request('GET', '/legal/notice');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('this Acme &lt;Cloud&gt; &amp; "Co" instance', $html);
        self::assertStringNotContainsString('<Cloud>', $html);
        self::assertStringNotContainsString('%brand%', $html);
    }

    public function testARenamedBrandIsNeverAnsweredWithA304(): void
    {
        $kernel = $this->bootKernel([], ['brand_provider']);
        $this->store($kernel, 'terms', 'es', 'Condiciones', self::BODY);
        $client = $this->client($kernel);
        $client->request('GET', '/es/legal/terms');

        $first = $client->getResponse();
        $etag  = $first->getEtag();
        self::assertIsString($etag);
        self::assertNull($first->headers->get('Last-Modified'), 'Validated by the ETag only');

        $client->request('GET', '/es/legal/terms', server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertSame(304, $client->getResponse()->getStatusCode());

        $this->provider($kernel)->name = 'Renamed';
        $client->request('GET', '/es/legal/terms', server: ['HTTP_IF_NONE_MATCH' => $etag, 'HTTP_IF_MODIFIED_SINCE' => 'Fri, 01 Jan 2100 00:00:00 GMT']);
        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Condiciones de Renamed', (string) $response->getContent());
        self::assertNotSame($etag, $response->getEtag());
    }

    public function testAFailingProviderFallsBackToBrandNameWithoutLeaking(): void
    {
        $kernel = $this->bootKernel(['brand_name' => 'Configured'], ['brand_provider']);
        $this->store($kernel, 'terms', 'es', 'Condiciones', self::BODY);
        $this->provider($kernel)->fail = true;
        $client                        = $this->client($kernel);
        $client->request('GET', '/es/legal/terms');

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();
        self::assertStringContainsString('<h1>Condiciones de Configured</h1>', $html);
        self::assertStringNotContainsString('SQLSTATE', $html);
        self::assertStringNotContainsString('provider-detail', $html);
        self::assertNotNull($response->headers->get('Last-Modified'), 'Without a brand the 1.5 headers apply');
        self::assertNull($response->getEtag());
    }

    public function testModeOffIgnoresTheProvider(): void
    {
        foreach (['off', ['mode' => 'off']] as $brand) {
            $kernel = $this->bootKernel(['brand_name' => 'Configured', 'brand' => $brand], ['brand_provider']);
            self::assertFalse($this->testContainer($kernel)->has(LegalBrand::class));
            $this->store($kernel, 'terms', 'es', 'Condiciones', self::BODY);
            $client = $this->client($kernel);
            $client->request('GET', '/es/legal/terms');

            $response = $client->getResponse();
            self::assertStringContainsString('<h1>Condiciones de Configured</h1>', (string) $response->getContent());
            self::assertNotNull($response->headers->get('Last-Modified'));
        }
    }

    public function testThePlaceholderGateCountsTheProviderNameAsResolved(): void
    {
        $kernel      = $this->bootKernel(['locales' => ['en']], ['brand_provider']);
        $application = new Application($kernel);
        $tester      = new CommandTester($application->find('nowo:legal-kit:check-placeholders'));
        foreach (['notice', 'privacy', 'terms', 'cookies'] as $slug) {
            $this->store($kernel, $slug, 'en', 'Real ' . $slug, '<p>Real text of %brand%</p>');
        }

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
    }

    private function provider(TestKernel $kernel): TestBrandProvider
    {
        $provider = $this->testContainer($kernel)->get(TestBrandProvider::class);
        self::assertInstanceOf(TestBrandProvider::class, $provider);

        return $provider;
    }

    private function brand(TestKernel $kernel): LegalBrand
    {
        $brand = $this->testContainer($kernel)->get(LegalBrand::class);
        self::assertInstanceOf(LegalBrand::class, $brand);

        return $brand;
    }
}
