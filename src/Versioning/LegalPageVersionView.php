<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Versioning;

use DateTimeImmutable;
use Nowo\LegalKitBundle\Versioning\Entity\LegalPageVersion;

/**
 * Persistence-neutral, read-only view of one published legal page version (1.10, architecture bar rule 1): what
 * {@see LegalPageVersionsInterface} returns instead of the Doctrine entity.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LegalPageVersionView
{
    /**
     * @param string $slug Page slug
     * @param int $version Version number (≥ 2: version 1 is implicit)
     * @param bool $requiresAcceptance Whether subjects must accept it again
     * @param string $source editor|upgrade
     * @param DateTimeImmutable $publishedAt Publication time
     * @param ?string $seedRevision Seed revision published by upgrade-seed, null otherwise
     * @param array<string, string> $changeSummary Locale => plain-text summary
     */
    public function __construct(
        public string $slug,
        public int $version,
        public bool $requiresAcceptance,
        public string $source,
        public DateTimeImmutable $publishedAt,
        public ?string $seedRevision = null,
        public array $changeSummary = [],
    ) {
    }

    /**
     * View of a version row.
     *
     * @return self The view
     */
    public static function fromEntity(LegalPageVersion $version): self
    {
        return new self($version->getSlug(), $version->getVersion(), $version->requiresAcceptance(), $version->getSource(), $version->getPublishedAt(), $version->getSeedRevision(), $version->getChangeSummary());
    }

    /**
     * Summary in a locale (null when none).
     *
     * @return ?string The summary
     */
    public function summaryFor(string $locale): ?string
    {
        return $this->changeSummary[$locale] ?? null;
    }
}
