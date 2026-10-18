<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Functional;

use Nowo\LegalKitBundle\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Routing\RouterInterface;

/**
 * Admin pages: access (REQ-UI-002), matrix, editor (seed prefill, sanitising, empty body), restore with CSRF.
 */
final class AdminTest extends KernelTestCase
{
    public function testAnonymousIsAskedToAuthenticate(): void
    {
        $client = $this->client($this->bootKernel());
        $client->request('GET', '/admin/legal');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testUserWithoutTheRoleIsDenied(): void
    {
        $client = $this->adminClient($this->bootKernel(), 'editor');
        $client->request('GET', '/admin/legal');

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testIndexShowsEveryPageAndLocale(): void
    {
        $kernel = $this->bootKernel();
        $this->store($kernel, 'privacy', 'es', 'Privacidad', '<p>Texto real</p>');
        $client = $this->adminClient($kernel);
        $client->request('GET', '/admin/legal');

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $html = (string) $response->getContent();
        self::assertStringContainsString('data-testid="nowo-legal-kit-admin-index"', $html);
        foreach (['notice', 'privacy', 'terms', 'cookies'] as $slug) {
            foreach (['en', 'es', 'de'] as $locale) {
                self::assertStringContainsString('href="/admin/legal/' . $slug . '/' . $locale . '"', $html);
            }
        }
        self::assertStringContainsString('Custom', $html);
        self::assertStringContainsString('Built-in', $html);
        self::assertMatchesRegularExpression('/\d+ placeholders/', $html);
    }

    public function testEditStartsFromTheSeedAndSavesSanitisedHtml(): void
    {
        $kernel  = $this->bootKernel();
        $client  = $this->adminClient($kernel);
        $crawler = $client->request('GET', '/admin/legal/notice/de');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Impressum', $html);
        self::assertStringContainsString('href="/admin/legal/notice/en"', $html, 'One tab per locale');
        self::assertStringContainsString('href="/de/legal/notice"', $html, 'View public page');
        self::assertStringNotContainsString('nowo_legal_kit_reset', $html, 'No restore without a stored copy');
        self::assertStringContainsString('%brand%', $html, 'The editor keeps the tokens');

        $form                                   = $crawler->filter('form[name="nowo_legal_kit_document"]')->form();
        $form['nowo_legal_kit_document[title]'] = 'Impressum (Acme)';
        $form['nowo_legal_kit_document[body]']  = '<h1>Impressum</h1><p style="color:red" onmouseover="x()">Acme GmbH</p><iframe src="https://evil.test"></iframe>';
        $client->submit($form);

        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('/admin/legal/notice/de', $client->getResponse()->headers->get('Location'));
        $stored = $this->find($kernel, 'notice', 'de');
        self::assertNotNull($stored);
        self::assertSame('Impressum (Acme)', $stored->getTitle());
        self::assertSame('<h1>Impressum</h1><p>Acme GmbH</p>', $stored->getBody());

        $client->followRedirect();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Legal page saved for this locale.', $html);
        self::assertStringContainsString('nowo_legal_kit_reset', $html, 'Restore is offered for a stored copy');
    }

    public function testEmptyBodyIsRejected(): void
    {
        $kernel                                = $this->bootKernel();
        $client                                = $this->adminClient($kernel);
        $crawler                               = $client->request('GET', '/admin/legal/terms/en');
        $form                                  = $crawler->filter('form[name="nowo_legal_kit_document"]')->form();
        $form['nowo_legal_kit_document[body]'] = '<script>only()</script><p> </p>';
        $client->submit($form);

        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertNull($this->find($kernel, 'terms', 'en'));
        $client->followRedirect();
        self::assertStringContainsString('Add some text', (string) $client->getResponse()->getContent());
    }

    public function testInvalidFormAnswers422(): void
    {
        $client                                 = $this->adminClient($this->bootKernel());
        $crawler                                = $client->request('GET', '/admin/legal/terms/en');
        $form                                   = $crawler->filter('form[name="nowo_legal_kit_document"]')->form();
        $form['nowo_legal_kit_document[title]'] = '';
        $client->submit($form);

        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testRestoreDeletesTheStoredCopy(): void
    {
        $kernel = $this->bootKernel();
        $this->store($kernel, 'cookies', 'en', 'Cookies', '<p>Custom cookies</p>');
        $client  = $this->adminClient($kernel);
        $crawler = $client->request('GET', '/admin/legal/cookies/en');
        $form    = $crawler->filter('form[name="nowo_legal_kit_reset"]')->form();
        $client->submit($form);

        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertNull($this->find($kernel, 'cookies', 'en'));
        $client->followRedirect();
        self::assertStringContainsString('The built-in text is published again', (string) $client->getResponse()->getContent());
    }

    public function testRestoreWithoutCsrfIsRefused(): void
    {
        $kernel = $this->bootKernel();
        $this->store($kernel, 'cookies', 'en', 'Cookies', '<p>Custom cookies</p>');
        $client = $this->adminClient($kernel);
        $client->request('POST', '/admin/legal/cookies/en/reset', ['nowo_legal_kit_reset' => ['_token' => 'forged']]);

        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertNotNull($this->find($kernel, 'cookies', 'en'));
        $client->followRedirect();
        self::assertStringContainsString('The form expired', (string) $client->getResponse()->getContent());
    }

    public function testRestoreAnswersGetWith405(): void
    {
        $client = $this->adminClient($this->bootKernel());
        $client->request('GET', '/admin/legal/cookies/en/reset');

        self::assertSame(405, $client->getResponse()->getStatusCode());
    }

    public function testUnknownLocaleOrPageIsNotFound(): void
    {
        $client = $this->adminClient($this->bootKernel());
        $client->request('GET', '/admin/legal/cookies/fr');
        self::assertSame(404, $client->getResponse()->getStatusCode());
        $client->request('GET', '/admin/legal/imprint/en');
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    #[IgnoreDeprecations]
    public function testLegacyAdminRouteAliasesStillGenerate(): void
    {
        $router = $this->bootKernel()->getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        self::assertSame('/admin/legal', $router->generate('admin_legal_index'));
        self::assertSame('/admin/legal/privacy/es', $router->generate('admin_legal_edit', ['slug' => 'privacy', 'locale' => 'es']));
        self::assertSame('/admin/legal/privacy/es/reset', $router->generate('admin_legal_reset', ['slug' => 'privacy', 'locale' => 'es']));
    }

    public function testLegacyAliasesCanBeDisabled(): void
    {
        $router = $this->bootKernel(['routing' => ['legacy_admin_aliases' => false]])->getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        self::assertNull($router->getRouteCollection()->getAlias('admin_legal_index'));
    }

    public function testDisabledWebUiHasNoAdminRoutes(): void
    {
        $kernel = $this->bootKernel(['web_ui' => ['enabled' => false]]);
        $client = $this->adminClient($kernel);
        $client->request('GET', '/admin/legal');

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testAllowUnauthenticatedOpensTheAdmin(): void
    {
        $client = $this->client($this->bootKernel(['security' => ['allow_unauthenticated' => true]]));
        $client->request('GET', '/admin/legal');

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testWebUiWithoutSecurityFailsTheBuild(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('requires symfony/security-bundle');

        $this->bootKernel([], [], false);
    }

    public function testCustomAccessChecker(): void
    {
        $client = $this->client($this->bootKernel(['security' => ['access_checker' => 'test.legal_kit.allow_all']], [], true, []));
        $client->request('GET', '/admin/legal');

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }
}
