<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Versioning\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\Model\AuditableInterface;
use Nowo\AuditKitBundle\Model\BlameableTrait;
use Nowo\AuditKitBundle\Model\TimestampableTrait;
use Nowo\LegalKitBundle\Versioning\LegalPageVersionRepository;

/**
 * One published version (≥ 2) of a legal page; version 1 is implicit. A version belongs to the page (slug), not to a
 * locale. Mapped only with `versioning.enabled`. `created_by_id` is the publisher (AuditKit blameable).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[ORM\Entity(repositoryClass: LegalPageVersionRepository::class)]
#[ORM\Table(name: self::TABLE)]
#[ORM\UniqueConstraint(name: 'uniq_legal_page_version_slug_version', columns: ['slug', 'version'])]
#[ORM\Index(name: 'idx_legal_page_version_created_by', columns: ['created_by_id'])]
#[ORM\Index(name: 'idx_legal_page_version_updated_by', columns: ['updated_by_id'])]
// Column names fixed whatever the host naming strategy.
#[ORM\AttributeOverrides([
    new ORM\AttributeOverride(name: 'createdAt', column: new ORM\Column(name: 'created_at', type: 'datetime_immutable', nullable: true)),
    new ORM\AttributeOverride(name: 'updatedAt', column: new ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)),
])]
class LegalPageVersion implements AuditableInterface
{
    use BlameableTrait;
    use TimestampableTrait;

    /** Base table name (the doctrine.table_prefix is prepended at metadata load). */
    public const string TABLE = 'legal_page_version';

    /** Published from the admin editor. */
    public const string SOURCE_EDITOR = 'editor';

    /** Published by nowo:legal-kit:upgrade-seed. */
    public const string SOURCE_UPGRADE = 'upgrade';

    /** Maximum length of one change summary (characters). */
    public const int SUMMARY_MAX_LENGTH = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param string $slug Page slug
     * @param int $version Version number (≥ 2)
     * @param bool $requiresAcceptance Users must accept / acknowledge this version
     * @param string $source editor|upgrade
     * @param DateTimeImmutable $publishedAt Publication time (clock)
     * @param ?string $seedRevision Seed revision published by upgrade-seed (idempotence), null otherwise
     * @param array<string, string> $changeSummary Locale => plain-text summary
     */
    public function __construct(
        #[ORM\Column(length: 32)]
        private string $slug,
        #[ORM\Column(type: Types::INTEGER)]
        private int $version,
        #[ORM\Column(name: 'requires_acceptance', type: Types::BOOLEAN)]
        private bool $requiresAcceptance,
        #[ORM\Column(length: 16)]
        private string $source,
        #[ORM\Column(name: 'published_at', type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $publishedAt,
        #[ORM\Column(name: 'seed_revision', length: 32, nullable: true)]
        private ?string $seedRevision = null,
        #[ORM\Column(name: 'change_summary', type: Types::JSON)]
        private array $changeSummary = [],
    ) {
    }

    /**
     * Database id (null until flushed).
     *
     * @return ?int The id
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Page slug.
     *
     * @return string The slug
     */
    public function getSlug(): string
    {
        return $this->slug;
    }

    /**
     * Version number.
     *
     * @return int The version
     */
    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * Whether users must accept / acknowledge this version.
     *
     * @return bool The flag
     */
    public function requiresAcceptance(): bool
    {
        return $this->requiresAcceptance;
    }

    /**
     * Where the version was published (editor|upgrade).
     *
     * @return string The source
     */
    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * Publication time.
     *
     * @return DateTimeImmutable The time
     */
    public function getPublishedAt(): DateTimeImmutable
    {
        return $this->publishedAt;
    }

    /**
     * Seed revision published by upgrade-seed, null for editor versions.
     *
     * @return ?string The revision
     */
    public function getSeedRevision(): ?string
    {
        return $this->seedRevision;
    }

    /**
     * Locale => plain-text change summary.
     *
     * @return array<string, string> The summaries
     */
    public function getChangeSummary(): array
    {
        return $this->changeSummary;
    }

    /**
     * Summary of a locale (null when none).
     *
     * @return ?string The summary
     */
    public function summaryFor(string $locale): ?string
    {
        return $this->changeSummary[$locale] ?? null;
    }

    /**
     * Sets (non-empty) or removes (empty) the summary of a locale.
     *
     * @return void Nothing (side effects only)
     */
    public function setSummary(string $locale, string $summary): void
    {
        $summaries = $this->changeSummary;
        if ($summary === '') {
            unset($summaries[$locale]);
        } else {
            $summaries[$locale] = $summary;
        }
        // @igor-ignore - Doctrine entity, not a container service
        $this->changeSummary = $summaries;
    }
}
