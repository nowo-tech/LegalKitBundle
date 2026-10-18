<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Document;

use DateTimeInterface;

/**
 * A legal page ready to publish: title (plain text) and body (sanitised HTML), tokens resolved.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class ResolvedLegalDocument
{
    public const string SOURCE_STORED = 'stored';

    public const string SOURCE_SEED = 'seed';

    public const string SOURCE_FALLBACK = 'fallback';

    /**
     * @param string $slug Page slug
     * @param string $locale Requested locale
     * @param string $title Plain-text title (auto-escape on output)
     * @param string $body Sanitised HTML body (the only value printed with |raw)
     * @param 'fallback'|'seed'|'stored' $source Where the text comes from
     * @param string $contentLocale Locale the text is written in (`lang`, canonical)
     * @param DateTimeInterface|null $updatedAt Last update of the stored row (null: seed)
     */
    public function __construct(
        public string $slug,
        public string $locale,
        public string $title,
        public string $body,
        public string $source,
        public string $contentLocale,
        public ?DateTimeInterface $updatedAt = null,
    ) {
    }

    /**
     * Whether an operator copy is published.
     *
     * @return bool The answer
     */
    public function isStored(): bool
    {
        return $this->source === self::SOURCE_STORED;
    }
}
