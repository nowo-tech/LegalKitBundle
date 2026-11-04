<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Acceptance\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceManager;
use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceRepository;

/**
 * One acceptance (terms) or acknowledgement (privacy information) of a page version by a subject. Insert-only: no IP,
 * no user agent, no free text. Mapped only with `acceptance.enabled`; erased through
 * {@see LegalAcceptanceManager::eraseFor()}.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[ORM\Entity(repositoryClass: LegalAcceptanceRepository::class, readOnly: true)]
#[ORM\Table(name: self::TABLE)]
#[ORM\UniqueConstraint(name: 'uniq_legal_acceptance_subject_slug_version', columns: ['subject_id', 'slug', 'version'])]
class LegalAcceptance
{
    /** Base table name (the doctrine.table_prefix is prepended at metadata load). */
    public const string TABLE = 'legal_acceptance';

    /** Pattern of a subject id. */
    public const string SUBJECT_PATTERN = '/^[A-Za-z0-9._:@-]{1,128}$/';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param string $subjectId Host subject id
     * @param string $slug Page slug
     * @param int $version Version accepted
     * @param string $locale Content locale shown
     * @param string $kind accept|acknowledge
     * @param string $contentHash SHA-256 of the offered title and body
     * @param DateTimeImmutable $acceptedAt Time of the record
     */
    public function __construct(
        #[ORM\Column(name: 'subject_id', length: 128)]
        private string $subjectId,
        #[ORM\Column(length: 32)]
        private string $slug,
        #[ORM\Column(type: Types::INTEGER)]
        private int $version,
        #[ORM\Column(length: 8)]
        private string $locale,
        #[ORM\Column(length: 16)]
        private string $kind,
        #[ORM\Column(name: 'content_hash', length: 64, options: ['fixed' => true])]
        private string $contentHash,
        #[ORM\Column(name: 'accepted_at', type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $acceptedAt,
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
     * Host subject id.
     *
     * @return string The id
     */
    public function getSubjectId(): string
    {
        return $this->subjectId;
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
     * Version accepted.
     *
     * @return int The version
     */
    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * Content locale shown.
     *
     * @return string The locale
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * accept|acknowledge.
     *
     * @return string The kind
     */
    public function getKind(): string
    {
        return $this->kind;
    }

    /**
     * SHA-256 of the offered title and body.
     *
     * @return string The hash
     */
    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    /**
     * Time of the record.
     *
     * @return DateTimeImmutable The time
     */
    public function getAcceptedAt(): DateTimeImmutable
    {
        return $this->acceptedAt;
    }
}
