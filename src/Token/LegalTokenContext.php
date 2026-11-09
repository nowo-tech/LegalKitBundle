<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Token;

use DateTimeInterface;

/**
 * What a token resolver knows about the page being rendered.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LegalTokenContext
{
    /**
     * @param string $slug Page slug
     * @param string $locale Locale of the request
     * @param DateTimeInterface|null $updatedAt Last update of the stored row (null: seed)
     * @param string|null $brandName `%brand%` to use instead of the brand provider and `brand_name` (plain text; set by
     *                               `verify-acceptance-hash --brand-name` to re-render a text accepted under a past name)
     */
    public function __construct(
        public string $slug,
        public string $locale,
        public ?DateTimeInterface $updatedAt = null,
        public ?string $brandName = null,
    ) {
    }
}
