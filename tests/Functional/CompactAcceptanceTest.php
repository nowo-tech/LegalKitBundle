<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Functional;

use Nowo\LegalKitBundle\Acceptance\PendingLegalPage;
use Nowo\LegalKitBundle\Tests\Support\PageFixtures;
use Nowo\LegalKitBundle\Tests\Support\TestKernel;
use Nowo\LegalKitBundle\Tests\Support\VersioningTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

use function str_repeat;

/**
 * The compact re-acceptance page (acceptance.template): same contract as the default page, one card, opt-in.
 */
final class CompactAcceptanceTest extends VersioningTestCase
{
    private const string TEMPLATE = '@NowoLegalKitBundle/acceptance/compact.html.twig';

    private function page(TestKernel $kernel): Crawler
    {
        $client = $this->adminClient($kernel);
        $client->setServerParameter('HTTP_ACCEPT', 'text/html,application/xhtml+xml');
        $client->request('GET', '/app/skip');

        return $client->request('GET', '/legal/accept');
    }

    private function twigFor(TestKernel $kernel): Environment
    {
        $this->service($kernel, RequestStack::class)->push(Request::create('/legal/accept'));

        return $this->service($kernel, Environment::class);
    }

    public function testDefaultStaysTheFullPage(): void
    {
        $crawler = $this->page($this->acceptanceKernel(['acceptance' => ['exempt_routes' => ['app_skip']]]));

        self::assertCount(1, $crawler->filter('[data-testid="nowo-legal-kit-acceptance"]'));
        self::assertCount(0, $crawler->filter('.nowo-legal-acceptance--compact'));
    }

    public function testCompactPageKeepsTheContractAndLoadsItsStyles(): void
    {
        $kernel  = $this->acceptanceKernel(['acceptance' => ['exempt_routes' => ['app_skip'], 'template' => self::TEMPLATE]]);
        $crawler = $this->page($kernel);

        self::assertCount(1, $crawler->filter('.nowo-legal-acceptance--compact[data-testid="nowo-legal-kit-acceptance"]'));
        self::assertCount(1, $crawler->filter('h1'), 'One heading level per card');
        self::assertCount(1, $crawler->filter('[data-testid="nowo-legal-kit-acceptance-terms"][data-nowo-legal-kind="accept"] input[type="checkbox"]'));
        self::assertCount(1, $crawler->filter('[data-testid="nowo-legal-kit-acceptance-privacy"][data-nowo-legal-kind="acknowledge"]'));
        self::assertCount(0, $crawler->filter('[data-testid="nowo-legal-kit-acceptance-privacy"] input[type="checkbox"]'), 'Privacy is never accepted');
        self::assertCount(0, $crawler->filter('input[type="checkbox"][checked]'), 'Nothing is pre-ticked');
        self::assertCount(1, $crawler->filter('[data-testid="nowo-legal-kit-acceptance-form"]'));
        self::assertCount(1, $crawler->filter('[data-testid="nowo-legal-kit-acceptance-submit"]'));
        self::assertSame('/legal/terms', $crawler->filter('[data-testid="legal-acceptance-read-terms"]')->attr('href'));
        self::assertSame('noopener', $crawler->filter('[data-testid="legal-acceptance-read-terms"]')->attr('rel'));
        self::assertCount(1, $crawler->filter('input[name="nowo_legal_kit_acceptance[_token]"]'), 'CSRF token printed by form_rest');
        self::assertStringContainsString('nowo-legal-kit.css', $crawler->filter('head')->html());
    }

    public function testCompactPageRecordsTheAcceptance(): void
    {
        $kernel = $this->acceptanceKernel(['acceptance' => ['exempt_routes' => ['app_skip'], 'template' => self::TEMPLATE]]);
        $client = $this->adminClient($kernel);
        $client->setServerParameter('HTTP_ACCEPT', 'text/html,application/xhtml+xml');
        $client->request('GET', '/app/skip');
        $form = $client->request('GET', '/legal/accept')->filter('form[name="nowo_legal_kit_acceptance"]')->form();

        $client->submit($form);
        self::assertSame(422, $client->getResponse()->getStatusCode(), 'Terms must be ticked');

        self::tick($form, 'nowo_legal_kit_acceptance[accept_terms]');
        $client->submit($form);
        self::assertSame(303, $client->getResponse()->getStatusCode());
    }

    public function testImpersonatingUserSeesNoSubmitButton(): void
    {
        $twig    = $this->twigFor($this->acceptanceKernel(['acceptance' => ['template' => self::TEMPLATE]]));
        $context = ['impersonating' => true, 'stale' => false, 'logout_url' => '/logout'] + PageFixtures::interstitial();
        $html    = new Crawler($twig->render(self::TEMPLATE, $context));

        self::assertCount(1, $html->filter('[data-testid="legal-acceptance-impersonating"][role="alert"]'));
        self::assertCount(0, $html->filter('[data-testid="nowo-legal-kit-acceptance-submit"]'));
        self::assertSame('/logout', $html->filter('[data-testid="legal-acceptance-sign-out"]')->attr('href'));
    }

    public function testChangeSummaryIsEscaped(): void
    {
        $twig    = $this->twigFor($this->acceptanceKernel(['acceptance' => ['template' => self::TEMPLATE]]));
        $context = PageFixtures::interstitial();
        /** @var list<PendingLegalPage> $pending */
        $pending    = $context['pending'];
        $pending[0] = new PendingLegalPage($pending[0]->page, 2, 2, 'accept', 1, '<i>Terms</i>', 'en', str_repeat('a', 64), "<script>alert(1)</script>\nSecond line");
        $html       = $twig->render(self::TEMPLATE, ['pending' => $pending] + $context);

        self::assertStringContainsString("&lt;script&gt;alert(1)&lt;/script&gt;<br />\nSecond line", $html);
        self::assertStringContainsString('&lt;i&gt;Terms&lt;/i&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)', $html);
    }
}
