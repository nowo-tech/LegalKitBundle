<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Seo;

use Nowo\LegalKitBundle\Catalog\LegalPage;
use Nowo\LegalKitBundle\Catalog\LegalPageCatalogInterface;
use Nowo\LegalKitBundle\Locale\LegalLocales;
use Nowo\SeoKitBundle\Service\SitemapUrlProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * SeoKit sitemap entries: every enabled legal page in every enabled locale, with hreflang alternates
 * (registered when SeoKit is installed and `seo.sitemap` is not false).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LegalSitemapProvider implements SitemapUrlProviderInterface
{
    /**
     * @param LegalPageCatalogInterface $catalog Enabled pages
     * @param LegalLocales $locales Enabled locales
     * @param UrlGeneratorInterface $urls URL generator
     * @param string $defaultLocaleStyle `routing.default_locale_style`
     */
    public function __construct(
        private LegalPageCatalogInterface $catalog,
        private LegalLocales $locales,
        private UrlGeneratorInterface $urls,
        private string $defaultLocaleStyle = 'unprefixed',
    ) {
    }

    /**
     * @return list<array{loc: string, changefreq: string, priority: string, alternates: list<array{hreflang: string, href: string}>}>
     */
    public function getEntries(Request $request): array
    {
        $entries = [];
        foreach ($this->catalog->pages() as $page) {
            $urls = [];
            foreach ($this->locales->all() as $locale) {
                $urls[$locale] = $this->url($page, $locale);
            }
            $alternates = [];
            foreach ($urls as $locale => $url) {
                $alternates[] = ['hreflang' => $locale, 'href' => $url];
            }
            $alternates[] = ['hreflang' => 'x-default', 'href' => $urls[$this->locales->defaultLocale()]];
            foreach ($urls as $url) {
                $entries[] = ['loc' => $url, 'changefreq' => 'yearly', 'priority' => '0.2', 'alternates' => $alternates];
            }
        }

        return $entries;
    }

    private function url(LegalPage $page, string $locale): string
    {
        if ($this->defaultLocaleStyle === 'unprefixed' && $locale === $this->locales->defaultLocale()) {
            return $this->urls->generate($page->unlocalizedRouteName(), [], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        return $this->urls->generate($page->routeName(), ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
