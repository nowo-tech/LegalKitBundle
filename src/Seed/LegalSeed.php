<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Seed;

/**
 * A seed text (tokens kept).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LegalSeed
{
    /**
     * @param string $title Plain-text title
     * @param string $body Unsanitised HTML body
     * @param string $locale Locale the seed is written in
     * @param bool $fallback True when it comes from `seed.fallback_locale`
     */
    public function __construct(
        public string $title,
        public string $body,
        public string $locale,
        public bool $fallback,
    ) {
    }
}
