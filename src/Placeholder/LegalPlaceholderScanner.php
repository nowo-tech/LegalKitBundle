<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Placeholder;

use Nowo\LegalKitBundle\Catalog\LegalPageCatalogInterface;
use Nowo\LegalKitBundle\Document\LegalDocumentResolver;
use Nowo\LegalKitBundle\Locale\LegalLocales;
use Nowo\LegalKitBundle\Repository\LegalDocumentRepository;
use Nowo\LegalKitBundle\Token\LegalTokenContext;
use Nowo\LegalKitBundle\Token\LegalTokenRenderer;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\Service\ResetInterface;

use function array_merge;
use function array_unique;
use function array_values;
use function str_contains;

/**
 * Engineering gate of REQ-CC-012: finds seed markers and unknown `%tokens%` in the effective text (stored row or seed)
 * of every enabled page × locale. One query for all stored rows; results memoised until {@see reset()} (per request
 * in a worker). Does not replace counsel review (REQ-CC-010).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[AutoconfigureTag('kernel.reset', ['method' => 'reset'])]
final class LegalPlaceholderScanner implements ResetInterface
{
    /** @var array<string, list<PlaceholderHit>> */
    private array $memo = [];

    /** @var list<string> */
    private readonly array $markers;

    /**
     * @param LegalPageCatalogInterface $catalog Enabled pages
     * @param LegalLocales $locales Enabled locales
     * @param LegalDocumentRepository $documents Stored rows
     * @param LegalDocumentResolver $resolver Effective text
     * @param LegalTokenRenderer $tokens Known token names
     * @param bool $builtinMarkers Include {@see BuiltinMarkers::MARKERS}
     * @param list<string> $extraMarkers `placeholders.extra_markers`
     */
    public function __construct(
        private readonly LegalPageCatalogInterface $catalog,
        private readonly LegalLocales $locales,
        private readonly LegalDocumentRepository $documents,
        private readonly LegalDocumentResolver $resolver,
        private readonly LegalTokenRenderer $tokens,
        bool $builtinMarkers = true,
        array $extraMarkers = [],
    ) {
        $this->markers = array_values(array_unique(array_merge($builtinMarkers ? BuiltinMarkers::MARKERS : [], $extraMarkers)));
    }

    /**
     * Hits of one locale, or of every enabled locale (default first).
     *
     * @return list<PlaceholderHit>
     */
    public function findHits(?string $locale = null): array
    {
        $key = $locale ?? '*';
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $locales = $locale !== null && $locale !== '' ? [$locale] : $this->locales->all();
        $stored  = $this->documents->findByLocales($locales);
        $hits    = [];
        foreach ($locales as $loc) {
            foreach ($this->catalog->slugs() as $slug) {
                $effective = $this->resolver->effective($slug, $loc, $stored[LegalDocumentRepository::key($slug, $loc)] ?? null);
                if ($effective === null) {
                    continue;
                }
                $text = $effective['title'] . "\n" . $effective['body'];
                foreach ($this->markers as $marker) {
                    if (str_contains($text, $marker)) {
                        $hits[] = new PlaceholderHit($slug, $loc, $marker, $effective['source'], PlaceholderHit::KIND_MARKER);
                    }
                }
                foreach ($this->tokens->unresolvedNames($text, new LegalTokenContext($slug, $loc)) as $name) {
                    $hits[] = new PlaceholderHit($slug, $loc, '%' . $name . '%', $effective['source'], PlaceholderHit::KIND_TOKEN);
                }
            }
        }

        return $this->memo[$key] = $hits;
    }

    /**
     * Whether any hit remains.
     *
     * @return bool The answer
     */
    public function hasPlaceholders(?string $locale = null): bool
    {
        return $this->findHits($locale) !== [];
    }

    /**
     * The markers searched for.
     *
     * @return list<string>
     */
    public function markers(): array
    {
        return $this->markers;
    }

    /**
     * Forgets memoised hits (kernel.reset; also call after editing a page in the same request).
     *
     * @return void Nothing (side effects only)
     */
    public function reset(): void
    {
        $this->memo = [];
    }
}
