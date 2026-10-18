<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use Nowo\LegalKitBundle\Catalog\LegalPage;
use Nowo\LegalKitBundle\Catalog\LegalPageCatalogInterface;
use Nowo\LegalKitBundle\Document\LegalDocumentResolver;
use Nowo\LegalKitBundle\Document\ResolvedLegalDocument;
use Nowo\LegalKitBundle\Event\LegalPageRenderEvent;
use Nowo\LegalKitBundle\Locale\LegalLocales;
use Nowo\LegalKitBundle\Routing\LegalRouteLoader;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

use function is_string;

/**
 * Public legal pages: the stored operator copy, else the seed (unique page on the guest layout, no JavaScript).
 *
 * `Cache-Control: private` by default (layouts may show the signed-in user); `public.cache.shared_max_age > 0`
 * opts into shared caches. `Last-Modified` follows the stored row. The content locale is published as the
 * `_content_locale` request attribute (layout `lang` / canonical).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LegalPageController
{
    public const string CONTENT_LOCALE_ATTRIBUTE = '_content_locale';

    /**
     * @param LegalPageCatalogInterface $catalog Enabled pages
     * @param LegalLocales $locales Enabled locales
     * @param LegalDocumentResolver $resolver Published text
     * @param Environment $twig Twig
     * @param UrlGeneratorInterface $urls URL generator
     * @param EventDispatcherInterface $dispatcher Dispatches LegalPageRenderEvent
     * @param string $template `web_ui.templates.public_page`
     * @param string|null $layoutTemplate `public.layout_template`
     * @param string $defaultLocaleStyle `routing.default_locale_style`
     * @param int $maxAge `public.cache.max_age`
     * @param int $sharedMaxAge `public.cache.shared_max_age`
     */
    public function __construct(
        private LegalPageCatalogInterface $catalog,
        private LegalLocales $locales,
        private LegalDocumentResolver $resolver,
        private Environment $twig,
        private UrlGeneratorInterface $urls,
        private EventDispatcherInterface $dispatcher,
        private string $template,
        private ?string $layoutTemplate,
        private string $defaultLocaleStyle,
        private int $maxAge = 0,
        private int $sharedMaxAge = 0,
    ) {
    }

    /**
     * Renders a legal page (or 301s `/{default}/…` to the bare URL with the unprefixed style).
     *
     * @return Response The result
     */
    public function show(Request $request): Response
    {
        $page   = $this->page($request);
        $locale = $request->getLocale();
        $route  = $request->attributes->get('_route');

        if ($this->defaultLocaleStyle === 'unprefixed' && $route === $page->routeName() && $locale === $this->locales->defaultLocale()) {
            return new RedirectResponse($this->withQuery($this->urls->generate($page->unlocalizedRouteName()), $request), Response::HTTP_MOVED_PERMANENTLY);
        }

        $document = $this->resolver->resolve($page->slug, $locale);
        if (!$document instanceof ResolvedLegalDocument) {
            throw new NotFoundHttpException('No text for this legal page.');
        }
        $request->attributes->set(self::CONTENT_LOCALE_ATTRIBUTE, $document->contentLocale);

        $alternates = [];
        foreach ($this->locales->all() as $alternate) {
            $alternates[$alternate] = $this->url($page, $alternate, UrlGeneratorInterface::ABSOLUTE_URL);
        }
        $event = new LegalPageRenderEvent($page->slug, $locale, $document->contentLocale, $document, $alternates, $alternates[$locale] ?? $request->getUri());
        $this->dispatcher->dispatch($event);

        $response = new Response($this->twig->render($this->template, [
            'page'            => $page,
            'document'        => $document,
            'alternates'      => $alternates,
            'layout_template' => $this->layoutTemplate,
            'legal_locale'    => $locale,
        ] + $event->templateVariables()));

        return $this->cache($response, $document, $request);
    }

    /**
     * Prefixed style: the bare URL redirects (302) to the default locale.
     *
     * @return RedirectResponse The result
     */
    public function bare(Request $request): RedirectResponse
    {
        $page = $this->page($request);

        return new RedirectResponse($this->withQuery($this->url($page, $this->locales->defaultLocale()), $request), Response::HTTP_FOUND);
    }

    private function page(Request $request): LegalPage
    {
        $slug = $request->attributes->get(LegalRouteLoader::SLUG_ATTRIBUTE);
        if (!is_string($slug) || !$this->catalog->has($slug)) {
            throw new NotFoundHttpException('Unknown legal page.');
        }

        return $this->catalog->page($slug);
    }

    private function url(LegalPage $page, string $locale, int $type = UrlGeneratorInterface::ABSOLUTE_PATH): string
    {
        if ($this->defaultLocaleStyle === 'unprefixed' && $locale === $this->locales->defaultLocale()) {
            return $this->urls->generate($page->unlocalizedRouteName(), [], $type);
        }

        return $this->urls->generate($page->routeName(), ['_locale' => $locale], $type);
    }

    private function withQuery(string $url, Request $request): string
    {
        $query = $request->getQueryString();

        return is_string($query) && $query !== '' ? $url . '?' . $query : $url;
    }

    private function cache(Response $response, ResolvedLegalDocument $document, Request $request): Response
    {
        if ($this->sharedMaxAge > 0) {
            $response->setPublic();
            $response->setSharedMaxAge($this->sharedMaxAge);
            // Opt-in shared cache: keep these headers even when the layout touched the session (flashes).
            $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        } else {
            $response->setPrivate();
        }
        if ($this->maxAge > 0) {
            $response->setMaxAge($this->maxAge);
        }
        if ($document->updatedAt instanceof DateTimeInterface) {
            $response->setLastModified(DateTimeImmutable::createFromInterface($document->updatedAt));
        }
        $response->isNotModified($request);

        return $response;
    }
}
