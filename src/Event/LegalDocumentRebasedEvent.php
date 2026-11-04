<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after an operator kept their copy against a changed built-in text ("Keep my text"). Public API (SemVer).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class LegalDocumentRebasedEvent extends Event
{
    /**
     * @param string $slug Page slug
     * @param string $locale Locale
     * @param string $seedRevision Seed revision the copy is now based on
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $locale,
        public readonly string $seedRevision,
    ) {
    }
}
