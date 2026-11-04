<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Versioning;

use Nowo\LegalKitBundle\Entity\LegalDocument;
use Nowo\LegalKitBundle\Sanitizer\LegalHtmlSanitizerInterface;
use Nowo\LegalKitBundle\Seed\LegalSeed;
use Nowo\LegalKitBundle\Seed\LegalSeedRegistry;

/**
 * Seed base of the stored copies (`legal_document.seed_revision` / `seed_hash`): which built-in text a copy started
 * from, so that an unedited copy can be refreshed and an edited one flagged when the host ships new texts.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LegalSeedTracker
{
    /**
     * @param LegalSeedRegistry $seeds Seed providers
     * @param LegalHtmlSanitizerInterface $sanitizer The bundle sanitizer
     * @param LegalSeedHasher $hasher Text hash
     * @param string $revision Current seed revision (`seed.revision` ?? `seed.date`)
     */
    public function __construct(
        private LegalSeedRegistry $seeds,
        private LegalHtmlSanitizerInterface $sanitizer,
        private LegalSeedHasher $hasher,
        private string $revision,
    ) {
    }

    /**
     * Current seed revision.
     *
     * @return string The revision
     */
    public function revision(): string
    {
        return $this->revision;
    }

    /**
     * Current built-in text of a page in a locale, sanitized (tokens kept); null without a seed.
     *
     * @return ?LegalSeed The seed
     */
    public function currentSeed(string $slug, string $locale): ?LegalSeed
    {
        $seed = $this->seeds->find($slug, $locale);
        if (!$seed instanceof LegalSeed) {
            return null;
        }

        return new LegalSeed($seed->title, $this->sanitizer->sanitize($seed->body), $seed->locale, $seed->fallback);
    }

    /**
     * Hash of the current built-in text (null without a seed).
     *
     * @return ?string The hash
     */
    public function currentSeedHash(string $slug, string $locale): ?string
    {
        $seed = $this->currentSeed($slug, $locale);

        return $seed instanceof LegalSeed ? $this->hasher->hash($seed->title, $seed->body) : null;
    }

    /**
     * Hash of a stored copy.
     *
     * @return string The hash
     */
    public function documentHash(LegalDocument $document): string
    {
        return $this->hasher->hash($document->getTitle(), $document->getBody());
    }

    /**
     * Bases the copy on the current built-in text (new rows, "Keep my text", "Use the new text", seed command).
     * Without a seed the base becomes unknown.
     *
     * @return void Nothing (side effects only)
     */
    public function rebase(LegalDocument $document): void
    {
        $hash = $this->currentSeedHash($document->getSlug(), $document->getLocale());
        $document->setSeedBase($hash === null ? null : $this->revision, $hash);
    }

    /**
     * Imports: the base is the current seed when the copy equals it, unknown otherwise (never overwritten later).
     *
     * @return void Nothing (side effects only)
     */
    public function stampImported(LegalDocument $document): void
    {
        $hash = $this->currentSeedHash($document->getSlug(), $document->getLocale());
        if ($hash !== null && $hash === $this->documentHash($document)) {
            $document->setSeedBase($this->revision, $hash);

            return;
        }
        $document->setSeedBase(null, null);
    }
}
