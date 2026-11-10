<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Support;

use DateTimeInterface;
use Nowo\LegalKitBundle\Document\LegalDocumentResolver;
use Nowo\LegalKitBundle\Document\LegalDocumentResolverInterface;
use Nowo\LegalKitBundle\Document\ResolvedLegalDocument;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use Nowo\LegalKitBundle\Seed\LegalSeed;

/**
 * Host decorator of the published texts (a hexagonal app's adapter): prefixes the title, delegates everything else.
 */
final readonly class TestHostDocumentResolver implements LegalDocumentResolverInterface
{
    public function __construct(
        private LegalDocumentResolver $inner,
    ) {
    }

    public function resolve(string $slug, string $locale, ?string $brandName = null): ?ResolvedLegalDocument
    {
        $document = $this->inner->resolve($slug, $locale, $brandName);
        if (!$document instanceof ResolvedLegalDocument) {
            return null;
        }

        return new ResolvedLegalDocument(
            $document->slug,
            $document->locale,
            'Host: ' . $document->title,
            $document->body,
            $document->source,
            $document->contentLocale,
            $document->updatedAt,
            $document->version,
        );
    }

    public function refusedSources(string $slug, string $locale): array
    {
        return $this->inner->refusedSources($slug, $locale);
    }

    public function render(string $slug, string $locale, string $title, string $body, ?DateTimeInterface $updatedAt = null): array
    {
        return $this->inner->render($slug, $locale, $title, $body, $updatedAt);
    }

    public function effective(string $slug, string $locale, ?LegalDocument $stored): ?array
    {
        return $this->inner->effective($slug, $locale, $stored);
    }

    public function seedForEditor(string $slug, string $locale): ?LegalSeed
    {
        return $this->inner->seedForEditor($slug, $locale);
    }
}
