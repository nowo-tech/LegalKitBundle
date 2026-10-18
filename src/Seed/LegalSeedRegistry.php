<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Seed;

/**
 * Chain of seed providers (priority order) with the optional `seed.fallback_locale`.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LegalSeedRegistry
{
    /** @var list<LegalSeedProviderInterface> */
    private array $providers;

    /**
     * @param iterable<LegalSeedProviderInterface> $providers Tagged providers, highest priority first
     * @param string|null $fallbackLocale Locale used when no provider supports the requested one
     */
    public function __construct(
        iterable $providers,
        private ?string $fallbackLocale = null,
    ) {
        $list = [];
        foreach ($providers as $provider) {
            $list[] = $provider;
        }
        $this->providers = $list;
    }

    /**
     * The seed of a page in a locale, falling back to `seed.fallback_locale`; null when nobody has one.
     */
    public function find(string $slug, string $locale): ?LegalSeed
    {
        $provider = $this->provider($slug, $locale);
        if ($provider instanceof LegalSeedProviderInterface) {
            return new LegalSeed($provider->title($slug, $locale), $provider->body($slug, $locale), $locale, false);
        }

        $fallback = $this->fallbackLocale;
        if ($fallback === null || $fallback === '' || $fallback === $locale) {
            return null;
        }
        $provider = $this->provider($slug, $fallback);
        if (!$provider instanceof LegalSeedProviderInterface) {
            return null;
        }

        return new LegalSeed($provider->title($slug, $fallback), $provider->body($slug, $fallback), $fallback, true);
    }

    /**
     * Locale the seed of `$slug` is shown in for a page in `$locale` (null: no seed).
     */
    public function contentLocale(string $slug, string $locale): ?string
    {
        if ($this->provider($slug, $locale) instanceof LegalSeedProviderInterface) {
            return $locale;
        }
        $fallback = $this->fallbackLocale;
        if ($fallback !== null && $fallback !== '' && $this->provider($slug, $fallback) instanceof LegalSeedProviderInterface) {
            return $fallback;
        }

        return null;
    }

    private function provider(string $slug, string $locale): ?LegalSeedProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($slug, $locale)) {
                return $provider;
            }
        }

        return null;
    }
}
