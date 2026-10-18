<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Seed;

use Nowo\LegalKitBundle\Catalog\LegalPageCatalogInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Default seed provider: renders `pages.<slug>.seed_template` with the translator switched to the target locale
 * (restored in `finally`, worker safe). Texts live in the `NowoLegalKitSeed` domain; a locale is supported when its
 * own catalogue defines `<slug>.title` (hosts add a locale or an extra page by adding that key).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[AsTaggedItem(priority: -100)]
final readonly class TwigSeedProvider implements LegalSeedProviderInterface
{
    public const string DOMAIN = 'NowoLegalKitSeed';

    /**
     * @param LegalPageCatalogInterface $catalog Enabled pages
     * @param Environment $twig Twig environment
     * @param TranslatorInterface $translator Translator (locale switched during the render)
     */
    public function __construct(
        private LegalPageCatalogInterface $catalog,
        private Environment $twig,
        private TranslatorInterface $translator,
    ) {
    }

    public function supports(string $slug, string $locale): bool
    {
        if (!$this->catalog->has($slug)) {
            return false;
        }
        if (!$this->translator instanceof TranslatorBagInterface) {
            return true;
        }

        return $this->translator->getCatalogue($locale)->defines($slug . '.title', self::DOMAIN);
    }

    public function title(string $slug, string $locale): string
    {
        return $this->translator->trans($slug . '.title', [], self::DOMAIN, $locale);
    }

    public function body(string $slug, string $locale): string
    {
        $template = $this->catalog->page($slug)->seedTemplate;
        if (!$this->translator instanceof LocaleAwareInterface) {
            return $this->twig->render($template, ['legal_slug' => $slug, 'legal_locale' => $locale]);
        }

        $previous = $this->translator->getLocale();
        $this->translator->setLocale($locale);
        try {
            return $this->twig->render($template, ['legal_slug' => $slug, 'legal_locale' => $locale]);
        } finally {
            $this->translator->setLocale($previous);
        }
    }
}
