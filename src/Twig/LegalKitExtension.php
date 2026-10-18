<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Twig;

use Nowo\LegalKitBundle\Catalog\LegalPage;
use Nowo\LegalKitBundle\Catalog\LegalPageCatalogInterface;
use Nowo\LegalKitBundle\Document\LegalDocumentResolver;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use Nowo\LegalKitBundle\Locale\LegalLocales;
use Nowo\LegalKitBundle\Placeholder\LegalPlaceholderScanner;
use Nowo\LegalKitBundle\Repository\LegalDocumentRepository;
use Nowo\LegalKitBundle\Routing\LegalRouteLoader;
use Nowo\LegalKitBundle\Seed\LegalSeedRegistry;
use Nowo\LegalKitBundle\Token\CookieInventoryTokenResolver;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

use function is_callable;

/**
 * Twig API of the kit (public, SemVer): footer links, placeholder hits (Ops banner), content locale, cookie
 * inventory table and the admin URL. Stateless; the request locale is read per call.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class LegalKitExtension extends AbstractExtension
{
    /**
     * @param LegalPageCatalogInterface $catalog Enabled pages
     * @param LegalLocales $locales Enabled locales
     * @param LegalDocumentRepository $documents Stored rows
     * @param LegalSeedRegistry $seeds Seeds
     * @param LegalPlaceholderScanner $scanner Placeholder hits
     * @param CookieInventoryTokenResolver $cookieInventory Inventory table
     * @param UrlGeneratorInterface $urls URL generator
     * @param TranslatorInterface $translator Labels
     * @param RequestStack $requestStack Current request (locale)
     * @param string $defaultLocaleStyle `routing.default_locale_style`
     * @param bool $webUiEnabled `web_ui.enabled`
     */
    public function __construct(
        private readonly LegalPageCatalogInterface $catalog,
        private readonly LegalLocales $locales,
        private readonly LegalDocumentRepository $documents,
        private readonly LegalSeedRegistry $seeds,
        private readonly LegalPlaceholderScanner $scanner,
        private readonly CookieInventoryTokenResolver $cookieInventory,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requestStack,
        private readonly string $defaultLocaleStyle = 'unprefixed',
        private readonly bool $webUiEnabled = true,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('nowo_legal_kit_links', $this->links(...)),
            new TwigFunction('nowo_legal_kit_placeholder_hits', $this->placeholderHits(...)),
            new TwigFunction('nowo_legal_kit_admin_url', $this->adminUrl(...)),
            new TwigFunction('legal_kit_content_locale', $this->contentLocale(...)),
            new TwigFunction('legal_kit_cookie_inventory', $this->cookieInventory->render(...), ['is_safe' => ['html']]),
            new TwigFunction('nowo_legal_kit_consent_widget', $this->consentWidget(...), ['needs_environment' => true]),
        ];
    }

    /**
     * Footer links (pages with `nav: true`) in the given or the request locale.
     *
     * @return list<array{slug: string, route: string, url: string, label: string}>
     */
    public function links(?string $locale = null): array
    {
        $locale = $this->locale($locale);
        $links  = [];
        foreach ($this->catalog->pages() as $page) {
            if (!$page->nav) {
                continue;
            }
            [$route, $params] = $this->route($page, $locale);
            $links[]          = [
                'slug'  => $page->slug,
                'route' => $route,
                'url'   => $this->urls->generate($route, $params),
                'label' => $this->translator->trans($page->labelKey(), [], 'NowoLegalKitBundle', $locale),
            ];
        }

        return $links;
    }

    /**
     * Placeholder hits of one or every enabled locale.
     *
     * @return list<array{slug: string, locale: string, marker: string, source: string, kind: string}>
     */
    public function placeholderHits(?string $locale = null): array
    {
        $hits = [];
        foreach ($this->scanner->findHits($locale) as $hit) {
            $hits[] = $hit->toArray();
        }

        return $hits;
    }

    /**
     * URL of the admin index, null when the admin UI is disabled.
     */
    public function adminUrl(): ?string
    {
        return $this->webUiEnabled ? $this->urls->generate(LegalRouteLoader::ADMIN_INDEX) : null;
    }

    /**
     * Locale the page is shown in for the given (or request) locale: the locale itself when an operator copy or a
     * seed exists in it, else `seed.fallback_locale`; null when the page has no text.
     */
    public function contentLocale(string $slug, ?string $locale = null): ?string
    {
        $locale = $this->locale($locale);
        if (!$this->catalog->has($slug)) {
            return null;
        }
        $stored = $this->documents->findOneBySlugAndLocale($slug, $locale);
        if ($stored instanceof LegalDocument && LegalDocumentResolver::hasText($stored->getBody())) {
            return $locale;
        }

        return $this->seeds->contentLocale($slug, $locale);
    }

    /**
     * Whether the CookieConsent widget renders on this page (so "Manage cookies" can open it); false without
     * CookieConsent.
     */
    public function consentWidget(Environment $twig): bool
    {
        $function = $twig->getFunction('nowo_cookie_consent_should_render');
        $callable = $function instanceof TwigFunction ? $function->getCallable() : null;
        if (!is_callable($callable)) {
            return false;
        }
        try {
            return (bool) $callable();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function route(LegalPage $page, string $locale): array
    {
        if ($this->defaultLocaleStyle === 'unprefixed' && $locale === $this->locales->defaultLocale()) {
            return [$page->unlocalizedRouteName(), []];
        }

        return [$page->routeName(), ['_locale' => $locale]];
    }

    private function locale(?string $locale): string
    {
        if ($locale !== null && $this->locales->has($locale)) {
            return $locale;
        }
        $request = $this->requestStack->getCurrentRequest();
        $current = $request?->getLocale();

        return $current !== null && $this->locales->has($current) ? $current : $this->locales->defaultLocale();
    }
}
