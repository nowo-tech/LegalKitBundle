<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Seed;

/**
 * Built-in (seed) text of legal pages; tagged `nowo_legal_kit.seed_provider` (priority), first supporting provider
 * wins (public API, SemVer). Bodies keep their `%token%` placeholders: tokens are resolved at render time only.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface LegalSeedProviderInterface
{
    public const string TAG = 'nowo_legal_kit.seed_provider';

    /**
     * Whether this provider has a seed of the page in this locale itself (no fallback translation).
     */
    public function supports(string $slug, string $locale): bool;

    /**
     * Plain-text title of the page in the locale.
     */
    public function title(string $slug, string $locale): string;

    /**
     * HTML body of the page in the locale (unsanitised; the bundle sanitises it).
     */
    public function body(string $slug, string $locale): string;
}
