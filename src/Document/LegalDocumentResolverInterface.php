<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Document;

use DateTimeInterface;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use Nowo\LegalKitBundle\Seed\LegalSeed;

/**
 * Port of the published legal texts (public API, SemVer): resolution order stored row with text → seed of the locale →
 * seed of `seed.fallback_locale`, tokens resolved, body sanitised on every render. Aliased to
 * {@see LegalDocumentResolver}; type-hint this interface in your application layer and decorate it if needed (the
 * bundle's public page, admin, acceptance gate and commands use it too).
 *
 * No access check: it reads texts only; the public page and admin controllers do their own checks.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface LegalDocumentResolverInterface
{
    /**
     * The published page, or null when there is neither a stored row nor a seed, or when the text that applies has no
     * text left after the sanitiser (such a page answers 404 and is never offered for acceptance).
     *
     * `$brandName` renders `%brand%` with that name instead of the current brand; null for the current brand.
     *
     * @return ?ResolvedLegalDocument The result
     */
    public function resolve(string $slug, string $locale, ?string $brandName = null): ?ResolvedLegalDocument;

    /**
     * Which texts of a page have no text left after the sanitiser, also a seed that a stored row hides today.
     *
     * @return list<'seed'|'stored'> The sources
     */
    public function refusedSources(string $slug, string $locale): array;

    /**
     * Renders a title and a body the way the public page does (tokens, then the sanitiser) without resolving the
     * stored copy.
     *
     * @return array{title: string, body: string}
     */
    public function render(string $slug, string $locale, string $title, string $body, ?DateTimeInterface $updatedAt = null): array;

    /**
     * Raw effective text (tokens kept) of a page: the stored row when it has text, else the seed. `$stored` is the
     * bundle's own row (bundle-internal: an application layer passes null).
     *
     * @return array{title: string, body: string, source: 'fallback'|'seed'|'stored'}|null
     */
    public function effective(string $slug, string $locale, ?LegalDocument $stored): ?array;

    /**
     * The seed (tokens kept, sanitised body) the editor starts from; null when there is none.
     *
     * @return ?LegalSeed The result
     */
    public function seedForEditor(string $slug, string $locale): ?LegalSeed;
}
