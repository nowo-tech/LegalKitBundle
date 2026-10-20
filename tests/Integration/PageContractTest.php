<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Integration;

use Nowo\LegalKitBundle\Sanitizer\DomAllowlistSanitizer;
use Nowo\LegalKitBundle\Tests\Support\PageFixtures;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;
use Twig\Environment;
use Twig\TwigFilter;
use Twig\TwigFunction;

use function dirname;

/**
 * REQ-UI-003 page contract: admin pages extend UiKit scaffolds, the public page is a declared unique page on the
 * guest layout; no <html>, CDN, inline handler or confirm(); framework classes only through theme slots; stub theme
 * reflected; no raw translation keys (strict missing-message rule).
 */
final class PageContractTest extends PageContractTestCase
{
    public static function pageFixtures(): iterable
    {
        yield 'admin index' => new PageFixture('@NowoLegalKitBundle/admin/index.html.twig', PageFixtures::index());
        yield 'admin index (empty)' => new PageFixture('@NowoLegalKitBundle/admin/index.html.twig', PageFixtures::index(true));
        yield 'admin edit (stored)' => new PageFixture('@NowoLegalKitBundle/admin/edit.html.twig', PageFixtures::edit());
        yield 'admin edit (seed)' => new PageFixture('@NowoLegalKitBundle/admin/edit.html.twig', PageFixtures::edit(false));
        yield 'public page (seed)' => new PageFixture('@NowoLegalKitBundle/public/page.html.twig', PageFixtures::publicPage());
        yield 'public page (stored)' => new PageFixture('@NowoLegalKitBundle/public/page.html.twig', PageFixtures::publicPage(true));
    }

    protected static function templatePaths(): array
    {
        return ['NowoLegalKitBundle' => dirname(__DIR__, 2) . '/src/Resources/views'];
    }

    protected static function translationDirs(): array
    {
        return [dirname(__DIR__, 2) . '/src/Resources/translations'];
    }

    protected static function templatesDocument(): string
    {
        return dirname(__DIR__, 2) . '/docs/TEMPLATES.md';
    }

    protected static function strictMissingTranslations(): bool
    {
        return true;
    }

    protected function configureTwig(Environment $twig): void
    {
        $twig->addFilter(new TwigFilter('nowo_legal_kit_sanitize_html', (new DomAllowlistSanitizer())->sanitize(...), ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('nowo_legal_kit_links', static fn (?string $locale = null): array => [
            ['slug' => 'notice', 'route' => 'legal_notice_unlocalized', 'url' => '/legal/notice', 'label' => 'Legal notice'],
            ['slug' => 'privacy', 'route' => 'legal_privacy_unlocalized', 'url' => '/legal/privacy', 'label' => 'Privacy policy'],
        ]));
    }
}
