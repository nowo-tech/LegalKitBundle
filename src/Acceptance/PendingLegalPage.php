<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Acceptance;

use Nowo\LegalKitBundle\Catalog\LegalPage;

/**
 * A page a subject still has to accept or acknowledge, with the text offered.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class PendingLegalPage
{
    /**
     * @param LegalPage $page The page
     * @param int $currentVersion Version that accepting records
     * @param int $requiredVersion Lowest version that satisfies the page
     * @param string $kind accept|acknowledge
     * @param ?int $acceptedVersion Highest version the subject accepted, null when never
     * @param string $title Resolved title (plain text)
     * @param string $contentLocale Locale of the offered text
     * @param string $contentHash SHA-256 of the offered title and body
     * @param ?string $changeSummary Plain-text summary of the current version (null when none)
     */
    public function __construct(
        public LegalPage $page,
        public int $currentVersion,
        public int $requiredVersion,
        public string $kind,
        public ?int $acceptedVersion,
        public string $title,
        public string $contentLocale,
        public string $contentHash,
        public ?string $changeSummary = null,
    ) {
    }

    /**
     * Whether the page is accepted (terms) rather than acknowledged.
     */
    public function isAccept(): bool
    {
        return $this->kind === LegalPage::ACCEPTANCE_ACCEPT;
    }
}
