<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Placeholder;

/**
 * One placeholder found in a published page.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class PlaceholderHit
{
    public const string KIND_MARKER = 'marker';

    public const string KIND_TOKEN = 'token';

    /**
     * @param string $slug Page slug
     * @param string $locale Locale
     * @param string $marker Marker text, or `%name%` for an unknown token
     * @param 'stored'|'seed'|'fallback' $source Where the effective text comes from
     * @param 'marker'|'token' $kind Hit kind
     */
    public function __construct(
        public string $slug,
        public string $locale,
        public string $marker,
        public string $source,
        public string $kind,
    ) {
    }

    /**
     * Array shape of the JSON output and of the 1.x copies (`slug`, `locale`, `marker`, `source`, `kind`).
     *
     * @return array{slug: string, locale: string, marker: string, source: string, kind: string}
     */
    public function toArray(): array
    {
        return ['slug' => $this->slug, 'locale' => $this->locale, 'marker' => $this->marker, 'source' => $this->source, 'kind' => $this->kind];
    }
}
