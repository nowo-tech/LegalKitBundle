<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\EventListener;

use Nowo\LegalKitBundle\Event\LegalPageRenderEvent;
use Nowo\LegalKitBundle\Locale\LegalLocales;
use Nowo\SeoKitBundle\Service\SeoRuntime;

/**
 * SeoKit head bridge (`seo.head`): title, canonical and hreflang alternates of a legal page (+ x-default). Robots
 * stay with SeoKit (indexing policy of the host).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class SeoKitHeadListener
{
    /**
     * @param SeoRuntime $runtime SeoKit per-request overrides
     * @param LegalLocales $locales Enabled locales (x-default = default locale)
     */
    public function __construct(
        private SeoRuntime $runtime,
        private LegalLocales $locales,
    ) {
    }

    /**
     * Writes the head overrides of the page.
     */
    public function __invoke(LegalPageRenderEvent $event): void
    {
        $alternates = [];
        foreach ($event->alternates as $locale => $url) {
            $alternates[] = ['locale' => $locale, 'url' => $url, 'hreflang' => $locale];
        }
        $default = $this->locales->defaultLocale();
        if (isset($event->alternates[$default])) {
            $alternates[] = ['locale' => 'x-default', 'url' => $event->alternates[$default], 'hreflang' => 'x-default'];
        }

        $this->runtime->set([
            'title'      => $event->document->title,
            'canonical'  => $event->canonicalUrl,
            'alternates' => $alternates,
        ]);
    }
}
