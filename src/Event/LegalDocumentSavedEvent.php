<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after flush when an operator copy of a page is saved (created or updated) (cache purge, sitemap invalidation; public API,
 * SemVer).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class LegalDocumentSavedEvent extends Event
{
    /**
     * @param string $slug Page slug
     * @param string $locale Locale
     * @param ?string $publication minor|new_version with versioning on (since 1.4), null otherwise
     * @param ?int $version Page version after the save (since 1.4), null without versioning
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $locale,
        public readonly ?string $publication = null,
        public readonly ?int $version = null,
    ) {
    }
}
