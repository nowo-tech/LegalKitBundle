<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\Model\AuditableInterface;
use Nowo\AuditKitBundle\Model\BlameableTrait;
use Nowo\AuditKitBundle\Model\TimestampableTrait;
use Nowo\LegalKitBundle\Repository\LegalDocumentRepository;

/**
 * Operator copy of one legal page in one locale. No row = the built-in seed is published.
 *
 * Maps the `legal_document` table of the six adopting apps as is (column names, lengths, nullability, index names):
 * `created_by_id` / `updated_by_id` target AuditKit's AuditUserInterface (resolve_target_entities → host user).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[ORM\Entity(repositoryClass: LegalDocumentRepository::class)]
#[ORM\Table(name: self::TABLE)]
#[ORM\UniqueConstraint(name: 'uniq_legal_document_slug_locale', columns: ['slug', 'locale'])]
#[ORM\Index(name: 'idx_legal_document_created_by', columns: ['created_by_id'])]
#[ORM\Index(name: 'idx_legal_document_updated_by', columns: ['updated_by_id'])]
class LegalDocument implements AuditableInterface
{
    use BlameableTrait;
    use TimestampableTrait;

    /** Base table name (the doctrine.table_prefix is prepended at metadata load). */
    public const string TABLE = 'legal_document';

    /** Maximum title length (VARCHAR 180). */
    public const int TITLE_MAX_LENGTH = 180;

    /** Maximum body length accepted by the editor and imports (characters; bounds the sanitising cost per render). */
    public const int BODY_MAX_LENGTH = 1_000_000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: self::TITLE_MAX_LENGTH)]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $body = '';

    /**
     * @param string $slug Page slug (catalogue key)
     * @param string $locale Locale code
     */
    public function __construct(
        #[ORM\Column(length: 32)]
        private string $slug,
        #[ORM\Column(length: 8)]
        private string $locale,
    ) {
    }

    /**
     * Database id (null until flushed).
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Page slug.
     */
    public function getSlug(): string
    {
        return $this->slug;
    }

    /**
     * Locale code.
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * Plain-text title (always auto-escaped on output).
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * Sets the plain-text title.
     */
    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    /**
     * Stored HTML body (sanitised on every write and again on render; tokens kept).
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Sets the HTML body.
     */
    public function setBody(string $body): void
    {
        $this->body = $body;
    }
}
