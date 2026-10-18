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
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $locale,
    ) {
    }
}
