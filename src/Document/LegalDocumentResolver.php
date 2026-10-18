<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Document;

use Nowo\LegalKitBundle\Entity\LegalDocument;
use Nowo\LegalKitBundle\Repository\LegalDocumentRepository;
use Nowo\LegalKitBundle\Sanitizer\LegalHtmlSanitizerInterface;
use Nowo\LegalKitBundle\Seed\LegalSeed;
use Nowo\LegalKitBundle\Seed\LegalSeedRegistry;
use Nowo\LegalKitBundle\Token\LegalTokenContext;
use Nowo\LegalKitBundle\Token\LegalTokenRenderer;

use function html_entity_decode;
use function preg_replace;
use function strip_tags;

use const ENT_HTML5;
use const ENT_QUOTES;

/**
 * Resolution order of a public page: stored row with text → seed of the locale → seed of `seed.fallback_locale`.
 * Tokens are resolved first, then the body is sanitised on every render (defence in depth for rows written by SQL /
 * restore, and token values — host resolvers included — can never inject markup or URLs past the allowlist).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LegalDocumentResolver
{
    /**
     * @param LegalDocumentRepository $documents Stored rows
     * @param LegalSeedRegistry $seeds Seed providers
     * @param LegalHtmlSanitizerInterface $sanitizer HTML sanitiser
     * @param LegalTokenRenderer $tokens Token chain
     */
    public function __construct(
        private LegalDocumentRepository $documents,
        private LegalSeedRegistry $seeds,
        private LegalHtmlSanitizerInterface $sanitizer,
        private LegalTokenRenderer $tokens,
    ) {
    }

    /**
     * The published page, or null when there is neither a stored row nor a seed.
     *
     * @return ?ResolvedLegalDocument The result
     */
    public function resolve(string $slug, string $locale): ?ResolvedLegalDocument
    {
        $stored = $this->documents->findOneBySlugAndLocale($slug, $locale);
        if ($stored instanceof LegalDocument && self::hasText($stored->getBody())) {
            $context = new LegalTokenContext($slug, $locale, $stored->getUpdatedAt() ?? $stored->getCreatedAt());

            return new ResolvedLegalDocument(
                $slug,
                $locale,
                $this->tokens->renderText($stored->getTitle(), $context),
                $this->sanitizer->sanitize($this->tokens->renderHtml($stored->getBody(), $context)),
                ResolvedLegalDocument::SOURCE_STORED,
                $locale,
                $context->updatedAt,
            );
        }

        $seed = $this->seeds->find($slug, $locale);
        if (!$seed instanceof LegalSeed) {
            return null;
        }
        $context = new LegalTokenContext($slug, $locale);

        return new ResolvedLegalDocument(
            $slug,
            $locale,
            $this->tokens->renderText($seed->title, $context),
            $this->sanitizer->sanitize($this->tokens->renderHtml($seed->body, $context)),
            $seed->fallback ? ResolvedLegalDocument::SOURCE_FALLBACK : ResolvedLegalDocument::SOURCE_SEED,
            $seed->locale,
        );
    }

    /**
     * Raw effective text (tokens kept) of a page: the stored row when it has text, else the seed; used by the
     * placeholder scanner. `$stored` avoids one query per page when the caller loaded the rows already.
     *
     * @return array{title: string, body: string, source: 'fallback'|'seed'|'stored'}|null
     */
    public function effective(string $slug, string $locale, ?LegalDocument $stored): ?array
    {
        if ($stored instanceof LegalDocument && self::hasText($stored->getBody())) {
            return ['title' => $stored->getTitle(), 'body' => $stored->getBody(), 'source' => ResolvedLegalDocument::SOURCE_STORED];
        }
        $seed = $this->seeds->find($slug, $locale);
        if (!$seed instanceof LegalSeed) {
            return null;
        }

        return [
            'title'  => $seed->title,
            'body'   => $seed->body,
            'source' => $seed->fallback ? ResolvedLegalDocument::SOURCE_FALLBACK : ResolvedLegalDocument::SOURCE_SEED,
        ];
    }

    /**
     * The seed (tokens kept, sanitised body) the editor starts from; null when there is none.
     *
     * @return ?LegalSeed The result
     */
    public function seedForEditor(string $slug, string $locale): ?LegalSeed
    {
        $seed = $this->seeds->find($slug, $locale);
        if (!$seed instanceof LegalSeed) {
            return null;
        }

        return new LegalSeed($seed->title, $this->sanitizer->sanitize($seed->body), $seed->locale, $seed->fallback);
    }

    /**
     * Whether an HTML body has visible text.
     *
     * @return bool The answer
     */
    public static function hasText(string $html): bool
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return preg_replace('/[\s\x{00A0}\x{200B}]+/u', '', $text) !== '';
    }
}
