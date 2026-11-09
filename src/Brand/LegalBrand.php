<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Brand;

use Error;
use Nowo\Brand\Contract\Brand;
use Nowo\Brand\Contract\BrandProviderInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the brand of a `nowo-tech/brand-contracts` {@see BrandProviderInterface} for the `%brand%` token (spec 220 S5,
 * LegalKit 1.6).
 *
 * Registered only with `nowo_legal_kit.brand.mode: auto` (the default) and brand-contracts installed. The provider is
 * an optional reference: without a provider service {@see self::name()} is null and `%brand%` stays the
 * `brand_name` configuration, byte for byte.
 *
 * Only the brand name is read here. The optional public header (`public.header`, LegalKit 1.7) reads the light logo
 * URL from {@see self::current()} and prints it as an escaped `<img src>`; no brand asset is ever read or inlined.
 *
 * Stateless: every call asks the provider again (providers memoise per request themselves), so a long-running
 * worker keeps nothing between requests. A failing provider is logged (a warning for an exception, an error for a
 * PHP {@see Error}, which usually means a bug in the provider) and the configuration applies: a legal page never fails
 * because of the brand, and the provider's message never reaches the page.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LegalBrand
{
    /**
     * @param BrandProviderInterface|null $provider The host's brand provider, when it has one
     * @param LoggerInterface|null $logger Logger for provider failures
     */
    public function __construct(
        private ?BrandProviderInterface $provider = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The current brand, or null without a provider or when the provider fails.
     *
     * @return Brand|null The brand
     */
    public function current(): ?Brand
    {
        if (!$this->provider instanceof BrandProviderInterface) {
            return null;
        }

        try {
            return $this->provider->getBrand();
        } catch (Throwable $e) {
            $message = 'LegalKitBundle: the brand provider failed, using nowo_legal_kit.brand_name.';
            if ($e instanceof Error) {
                $this->logger?->error($message, ['exception' => $e]);
            } else {
                $this->logger?->warning($message, ['exception' => $e]);
            }

            return null;
        }
    }

    /**
     * The brand name (plain text, never empty), or null when no brand applies.
     *
     * @return string|null The name
     */
    public function name(): ?string
    {
        return $this->current()?->name;
    }
}
